<?php

namespace App\Service\Vulns;

use Symfony\Component\Finder\Finder;

/**
 * Walks the STIG and SCAP XML corpus and extracts every IAVA / IAVB /
 * IAVT / CTO / CVE reference it finds, recording which rule (V-id +
 * version/release) cited it.
 *
 * The output is cached at resources/data/vulns_toc.json and consumed by
 * VulnsRegistry for the /vulnerabilities/* views.
 *
 * Reality check on what's in the corpus: IAVMs are largely DoD-private
 * and don't appear in published STIGs in any structured form. The scanner
 * finds:
 *   - A small handful of standalone IAVM-format IDs (YYYY-A-NNNN pattern)
 *   - Cyber Tasking Orders (CTOnnnn) — the only ID family that DISA puts
 *     into STIGs with any consistency
 *   - Direct CVE references in a few rules' descriptions
 *   - A larger pool of rules that *mention* "IAVA" in prose without
 *     naming a specific bulletin (these get flagged but bucketed into a
 *     single "prose mention" category)
 *
 * The /vulnerabilities/iavm page surfaces all of this honestly — sparse
 * but accurate is the right framing for users.
 */
class VulnsTocBuilder
{
    private string $stigDir;
    private string $scapDir;
    private string $tocPath;

    /** Per-instance regex cache (compiled once per builder lifetime). */
    private const RE_IAVM = '/\b(20\d{2})-([ABT])-(\d{4})\b/';
    // CTO IDs are 4-digit. Trailing (?!\d) instead of \b lets "CTO 0715rev 1"
    // match "CTO 0715" — STIG descriptions glue rev info onto the ID.
    private const RE_CTO  = '/\bCTO[\s\-_]*(\d{4})(?!\d)/i';
    private const RE_CVE  = '/\bCVE-(\d{4})-(\d{4,7})\b/i';
    private const RE_IAVM_PROSE = '/\b(IAVA|IAVB|IAVT|IAVM)\b/';

    public function __construct(string $projectDir)
    {
        $this->stigDir = $projectDir . '/resources/data/stig';
        $this->scapDir = $projectDir . '/resources/data/scap';
        $this->tocPath = $projectDir . '/resources/data/vulns_toc.json';
    }

    public function tocPath(): string
    {
        return $this->tocPath;
    }

    public function stigDir(): string
    {
        return $this->stigDir;
    }

    public function scapDir(): string
    {
        return $this->scapDir;
    }

    /**
     * Walk the corpus, build the toc structure. The optional callback fires
     * once per file scanned so the console command can render progress.
     *
     * Returns the toc array (also written via writeToc()).
     */
    public function rebuildAll(?callable $onFile = null): array
    {
        $rules = [];
        $stigFiles = 0;
        $scapFiles = 0;

        $stigFinder = (new Finder())->files()->in($this->stigDir)->name('*.xml');
        foreach ($stigFinder as $file) {
            $stigFiles++;
            $matches = $this->scanStigFile($file->getRealPath());
            if (!empty($matches)) {
                $rules = array_merge($rules, $matches);
            }
            if ($onFile !== null) {
                $onFile($file);
            }
        }

        $scapFinder = (new Finder())->files()->in($this->scapDir)->name('*.xml');
        foreach ($scapFinder as $file) {
            $scapFiles++;
            $matches = $this->scanScapFile($file->getRealPath());
            if (!empty($matches)) {
                $rules = array_merge($rules, $matches);
            }
            if ($onFile !== null) {
                $onFile($file);
            }
        }

        $stats = $this->summarize($rules, $stigFiles, $scapFiles);

        return [
            'generated_at'     => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
            'stats'            => $stats,
            'rules'            => $rules,
        ];
    }

    /** Persist the toc array as pretty JSON (diff-friendly under git). */
    public function writeToc(array $toc): void
    {
        file_put_contents(
            $this->tocPath,
            json_encode($toc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Parse one STIG XCCDF XML file. Returns a list of rule records with
     * any IAVM/CTO/CVE refs found in the rule body. STIG XCCDF uses xccdf 1.1
     * with a default namespace.
     */
    private function scanStigFile(string $filePath): array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            return [];
        }
        foreach ($xml->getDocNamespaces() as $prefix => $ns) {
            if ((string) $prefix === '') {
                $prefix = 'a';
            }
            $xml->registerXPathNamespace($prefix, $ns);
        }
        $xml->registerXPathNamespace('xmlns', 'http://checklists.nist.gov/xccdf/1.1');

        // Title for display; "release" for the URL — match StigTocBuilder logic.
        $titleQuery = $xml->xpath('/xmlns:Benchmark/xmlns:title/text()');
        $rawTitle = (string) (count($titleQuery) > 0 ? $titleQuery[0] : '');
        $title = $this->normalizeStigTitle($rawTitle);
        if ($title === '') {
            return [];
        }

        $relInfoQuery = $xml->xpath("/xmlns:Benchmark/xmlns:plain-text[@id='release-info']/text()");
        $relInfo = (string) (count($relInfoQuery) > 0 ? $relInfoQuery[0] : '');
        $release = preg_match('/Release: ([0-9\.]+) /', $relInfo, $m) ? $m[1] : 'UNK';

        $versionQuery = $xml->xpath('/xmlns:Benchmark/xmlns:version/text()');
        $version = (string) (count($versionQuery) > 0 ? $versionQuery[0] : '');

        $records = [];
        foreach ($xml->xpath('//xmlns:Group') as $group) {
            $vuln = (string) ($group['id'] ?? '');
            // Sub-element xpath doesn't inherit registered namespaces; re-bind
            // before reaching into the group.
            $group->registerXPathNamespace('xmlns', 'http://checklists.nist.gov/xccdf/1.1');
            $rule = $group->xpath('xmlns:Rule')[0] ?? null;
            if ($rule === null) {
                continue;
            }
            $ruleTitle = trim((string) ($rule->title ?? ''));

            // Dump the rule body (title + description + check + fix + ident +
            // ref + reference) to one big string and run regex over it.
            $body = $ruleTitle . ' ' . $this->extractTextBlob($rule);
            $extracted = $this->extractRefs($body);
            if ($this->isEmpty($extracted)) {
                continue;
            }
            $records[] = [
                'corpus'     => 'stig',
                'title'      => $title,
                'version'    => $version,
                'release'    => $release,
                'vuln'       => $vuln,
                'rule_title' => $ruleTitle,
            ] + $extracted;
        }

        return $records;
    }

    /**
     * SCAP files are XCCDF data streams where the actual benchmark lives in
     * a <component>/<xccdf:Benchmark>. Rules are namespaced under xccdf:.
     */
    private function scanScapFile(string $filePath): array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            return [];
        }
        foreach ($xml->getDocNamespaces(true) as $prefix => $ns) {
            if ((string) $prefix === '') {
                $prefix = 'a';
            }
            $xml->registerXPathNamespace($prefix, $ns);
        }
        $xml->registerXPathNamespace('xccdf', 'http://checklists.nist.gov/xccdf/1.2');

        $titleQuery = $xml->xpath('//xccdf:Benchmark/xccdf:title/text()');
        $rawTitle = (string) (count($titleQuery) > 0 ? $titleQuery[0] : '');
        $title = $this->normalizeStigTitle($rawTitle);
        if ($title === '') {
            return [];
        }

        $relInfoQuery = $xml->xpath("//xccdf:Benchmark/xccdf:plain-text[@id='release-info']/text()");
        $relInfo = (string) (count($relInfoQuery) > 0 ? $relInfoQuery[0] : '');
        $release = preg_match('/Release: ([0-9\.]+) /', $relInfo, $m) ? $m[1] : 'UNK';
        $versionQuery = $xml->xpath('//xccdf:Benchmark/xccdf:version/text()');
        $version = (string) (count($versionQuery) > 0 ? $versionQuery[0] : '');

        $records = [];
        foreach ($xml->xpath('//xccdf:Group') as $group) {
            $vuln = (string) ($group['id'] ?? '');
            // Strip the xccdf_mil.disa.stig_group_ prefix to get the V-id.
            if (preg_match('/(V-\d+)$/', $vuln, $m)) {
                $vuln = $m[1];
            }
            $group->registerXPathNamespace('xccdf', 'http://checklists.nist.gov/xccdf/1.2');
            $rule = $group->xpath('xccdf:Rule')[0] ?? null;
            if ($rule === null) {
                continue;
            }
            $ruleTitle = trim((string) ($rule->title ?? ''));
            $body = $ruleTitle . ' ' . $this->extractTextBlob($rule);
            $extracted = $this->extractRefs($body);
            if ($this->isEmpty($extracted)) {
                continue;
            }
            $records[] = [
                'corpus'     => 'scap',
                'title'      => $title,
                'version'    => $version,
                'release'    => $release,
                'vuln'       => $vuln,
                'rule_title' => $ruleTitle,
            ] + $extracted;
        }

        return $records;
    }

    private function normalizeStigTitle(string $raw): string
    {
        $t = str_replace(
            ['(STIG)', 'Security Technical Implementation Guide', ' SCAP Benchmark', ' MS ', 'Microsoft', '\/', '\\', '/'],
            '',
            $raw
        );
        $t = trim($t);
        return str_replace(' ', '_', $t);
    }

    /** Flatten a SimpleXMLElement to a string (children + attributes). */
    private function extractTextBlob(\SimpleXMLElement $el): string
    {
        // asXML() preserves embedded HTML in description/fix blocks (which is
        // where IAVA prose mentions tend to live). Strip tags afterward so the
        // regex doesn't match across element boundaries.
        $xml = $el->asXML();
        if ($xml === false) {
            return '';
        }
        // HTML-decode the encoded inner content (STIG descriptions are
        // double-encoded HTML inside the XML).
        $decoded = html_entity_decode(strip_tags(html_entity_decode($xml, ENT_QUOTES | ENT_HTML5)), ENT_QUOTES | ENT_HTML5);
        return $decoded;
    }

    private function extractRefs(string $body): array
    {
        $iavms = [];
        if (preg_match_all(self::RE_IAVM, $body, $m)) {
            foreach ($m[0] as $hit) {
                $iavms[$hit] = true;
            }
        }

        $ctos = [];
        if (preg_match_all(self::RE_CTO, $body, $m)) {
            foreach ($m[1] as $num) {
                $ctos['CTO' . $num] = true;
            }
        }

        $cves = [];
        if (preg_match_all(self::RE_CVE, $body, $m)) {
            foreach ($m[0] as $hit) {
                $cves[strtoupper($hit)] = true;
            }
        }

        $iavmProse = false;
        // Only flag prose mention if a *specific* IAVM ID wasn't already pulled
        // — the structured ID is more useful and supersedes the prose flag.
        if (empty($iavms) && preg_match(self::RE_IAVM_PROSE, $body)) {
            $iavmProse = true;
        }

        return [
            'iavms'      => array_keys($iavms),
            'ctos'       => array_keys($ctos),
            'cves'       => array_keys($cves),
            'iavm_prose' => $iavmProse,
        ];
    }

    private function isEmpty(array $extracted): bool
    {
        return empty($extracted['iavms'])
            && empty($extracted['ctos'])
            && empty($extracted['cves'])
            && !$extracted['iavm_prose'];
    }

    private function summarize(array $rules, int $stigFiles, int $scapFiles): array
    {
        $iavms = $ctos = $cves = [];
        $proseCount = 0;
        foreach ($rules as $r) {
            foreach ($r['iavms'] as $i) {
                $iavms[$i] = true;
            }
            foreach ($r['ctos'] as $c) {
                $ctos[$c] = true;
            }
            foreach ($r['cves'] as $c) {
                $cves[$c] = true;
            }
            if ($r['iavm_prose']) {
                $proseCount++;
            }
        }
        return [
            'stig_files_scanned'      => $stigFiles,
            'scap_files_scanned'      => $scapFiles,
            'rules_with_any_match'    => count($rules),
            'rules_with_iavm_prose'   => $proseCount,
            'distinct_iavms'          => count($iavms),
            'distinct_ctos'           => count($ctos),
            'distinct_cves'           => count($cves),
        ];
    }
}
