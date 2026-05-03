<?php

namespace App\Service\Vulns;

/**
 * Read-side facade over the KEV mirror + the STIG/SCAP-derived vulns_toc.
 *
 * Builds in-memory pivot tables on first access (per-request lifetime via
 * autowired singleton), so controller endpoints can answer "which rules
 * cite this IAVA?" / "which STIGs cite this CVE?" / "is this CVE in KEV?"
 * in O(1) lookups.
 *
 * 800-53 controls implicated in vulnerability management — surfaced on
 * detail pages so users can jump from a CVE to the controls that govern
 * its remediation lifecycle.
 */
class VulnsRegistry
{
    public const RELATED_CONTROLS = [
        'RA-5'  => 'Vulnerability Monitoring and Scanning',
        'SI-2'  => 'Flaw Remediation',
        'CM-6'  => 'Configuration Settings',
        'SI-7'  => 'Software, Firmware, and Information Integrity',
        'CM-8'  => 'System Component Inventory',
    ];

    private string $tocPath;

    /** Lazy-loaded raw toc + per-request pivots. */
    private ?array $toc = null;
    private ?array $byIavm = null;
    private ?array $byCto = null;
    private ?array $byCve = null;
    private ?array $proseRules = null;

    public function __construct(
        private KevLoader $kev,
        string $projectDir,
    ) {
        $this->tocPath = $projectDir . '/resources/data/vulns_toc.json';
    }

    public function kev(): KevLoader
    {
        return $this->kev;
    }

    public function tocExists(): bool
    {
        return is_file($this->tocPath);
    }

    public function tocStats(): array
    {
        $toc = $this->loadToc();
        return $toc['stats'] ?? [];
    }

    public function tocGeneratedAt(): ?string
    {
        $toc = $this->loadToc();
        return $toc['generated_at'] ?? null;
    }

    /** Combined landing-page summary (KEV + corpus). */
    public function summary(): array
    {
        $kevSummary = $this->kev->summary();
        $tocStats = $this->tocStats();

        return [
            'kev'                   => $kevSummary,
            'corpus'                => [
                'distinct_iavms'  => $tocStats['distinct_iavms'] ?? 0,
                'distinct_ctos'   => $tocStats['distinct_ctos'] ?? 0,
                'distinct_cves'   => $tocStats['distinct_cves'] ?? 0,
                'rules_with_match' => $tocStats['rules_with_any_match'] ?? 0,
                'rules_with_iavm_prose' => $tocStats['rules_with_iavm_prose'] ?? 0,
            ],
            'kev_meta'              => $this->kev->meta(),
            'toc_generated_at'      => $this->tocGeneratedAt(),
        ];
    }

    /* ---- KEV reads ---- */

    public function listKev(): array
    {
        return $this->kev->all();
    }

    public function getKev(string $cveId): ?array
    {
        return $this->kev->byCve($cveId);
    }

    /* ---- IAVMs / CTOs from corpus ---- */

    public function listIavms(): array
    {
        $this->buildPivots();
        $out = [];
        foreach ($this->byIavm as $id => $rules) {
            $out[] = [
                'id'         => $id,
                'kind'       => $this->iavmKind($id),
                'rule_count' => count($rules),
                'cves'       => $this->cvesForRules($rules),
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['id'], $b['id']));
        return $out;
    }

    public function listCtos(): array
    {
        $this->buildPivots();
        $out = [];
        foreach ($this->byCto as $id => $rules) {
            $out[] = [
                'id'         => $id,
                'rule_count' => count($rules),
                'cves'       => $this->cvesForRules($rules),
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['id'], $b['id']));
        return $out;
    }

    public function getIavm(string $id): ?array
    {
        $this->buildPivots();
        $key = strtoupper(trim($id));
        if (isset($this->byIavm[$key])) {
            $rules = $this->byIavm[$key];
            return [
                'id'    => $key,
                'kind'  => $this->iavmKind($key),
                'rules' => $rules,
                'cves'  => $this->cvesForRules($rules),
                'ctos'  => $this->ctosForRules($rules),
            ];
        }
        if (isset($this->byCto[$key])) {
            $rules = $this->byCto[$key];
            return [
                'id'    => $key,
                'kind'  => 'CTO',
                'rules' => $rules,
                'cves'  => $this->cvesForRules($rules),
                'iavms' => $this->iavmsForRules($rules),
            ];
        }
        return null;
    }

    /** All STIG/SCAP rules that contain prose IAVM mention without a specific ID. */
    public function proseMentionRules(): array
    {
        if ($this->proseRules !== null) {
            return $this->proseRules;
        }
        $toc = $this->loadToc();
        $out = [];
        foreach (($toc['rules'] ?? []) as $r) {
            if (!empty($r['iavm_prose'])) {
                $out[] = $r;
            }
        }
        return $this->proseRules = $out;
    }

    /* ---- CVEs ---- */

    /**
     * Distinct CVE list from KEV + corpus, with which sources mention it.
     * Useful for /vulnerabilities/cve listings.
     */
    public function listCves(): array
    {
        $this->buildPivots();
        $kevCves = [];
        foreach ($this->kev->all() as $entry) {
            $cve = strtoupper((string) ($entry['cveID'] ?? ''));
            if ($cve !== '') {
                $kevCves[$cve] = true;
            }
        }
        $all = array_unique(array_merge(array_keys($kevCves), array_keys($this->byCve)));
        sort($all);

        $out = [];
        foreach ($all as $cve) {
            $rules = $this->byCve[$cve] ?? [];
            $out[] = [
                'id'           => $cve,
                'in_kev'       => isset($kevCves[$cve]),
                'rule_count'   => count($rules),
            ];
        }
        return $out;
    }

    /**
     * Joined view of one CVE: any KEV record + any STIG/SCAP rules that
     * cite it + co-occurring IAVMs/CTOs. Returns null if the CVE is in
     * neither source.
     */
    public function getCve(string $cveId): ?array
    {
        $this->buildPivots();
        $key = strtoupper(trim($cveId));
        $kev = $this->kev->byCve($key);
        $rules = $this->byCve[$key] ?? [];
        if ($kev === null && empty($rules)) {
            return null;
        }
        return [
            'id'    => $key,
            'kev'   => $kev,
            'rules' => $rules,
            'iavms' => $this->iavmsForRules($rules),
            'ctos'  => $this->ctosForRules($rules),
        ];
    }

    /* ---- internals ---- */

    private function loadToc(): array
    {
        if ($this->toc !== null) {
            return $this->toc;
        }
        if (!$this->tocExists()) {
            return $this->toc = ['rules' => [], 'stats' => []];
        }
        $raw = @file_get_contents($this->tocPath);
        if ($raw === false) {
            return $this->toc = ['rules' => [], 'stats' => []];
        }
        $decoded = json_decode($raw, true);
        return $this->toc = is_array($decoded) ? $decoded : ['rules' => [], 'stats' => []];
    }

    private function buildPivots(): void
    {
        if ($this->byIavm !== null) {
            return;
        }
        $this->byIavm = $this->byCto = $this->byCve = [];
        $toc = $this->loadToc();
        foreach (($toc['rules'] ?? []) as $r) {
            foreach ($r['iavms'] ?? [] as $i) {
                $this->byIavm[$i][] = $r;
            }
            foreach ($r['ctos'] ?? [] as $c) {
                $this->byCto[$c][] = $r;
            }
            foreach ($r['cves'] ?? [] as $c) {
                $this->byCve[strtoupper($c)][] = $r;
            }
        }
        ksort($this->byIavm);
        ksort($this->byCto);
        ksort($this->byCve);
    }

    private function iavmKind(string $id): string
    {
        // "2010-A-0132" → letter is at offset 5; map A→IAVA, B→IAVB, T→IAVT.
        $letter = $id[5] ?? '';
        return match ($letter) {
            'A' => 'IAVA',
            'B' => 'IAVB',
            'T' => 'IAVT',
            default => 'IAVM',
        };
    }

    private function cvesForRules(array $rules): array
    {
        $cves = [];
        foreach ($rules as $r) {
            foreach ($r['cves'] ?? [] as $c) {
                $cves[strtoupper($c)] = true;
            }
        }
        return array_keys($cves);
    }

    private function iavmsForRules(array $rules): array
    {
        $iavms = [];
        foreach ($rules as $r) {
            foreach ($r['iavms'] ?? [] as $i) {
                $iavms[$i] = true;
            }
        }
        return array_keys($iavms);
    }

    private function ctosForRules(array $rules): array
    {
        $ctos = [];
        foreach ($rules as $r) {
            foreach ($r['ctos'] ?? [] as $c) {
                $ctos[$c] = true;
            }
        }
        return array_keys($ctos);
    }
}
