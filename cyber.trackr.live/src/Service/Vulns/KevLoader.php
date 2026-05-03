<?php

namespace App\Service\Vulns;

/**
 * Reads the locally mirrored CISA KEV (Known Exploited Vulnerabilities)
 * catalog at resources/data/kev/known_exploited_vulnerabilities.json.
 *
 * The file is fetched + refreshed by the app:kev:refresh console command.
 * Loader does in-memory caching per request so repeated lookups don't
 * re-parse the JSON.
 *
 * Upstream schema (CISA, Schema 2.03+):
 *   {
 *     "title": "CISA Catalog of Known Exploited Vulnerabilities",
 *     "catalogVersion": "2026.05.01",
 *     "dateReleased": "2026-05-01T13:00:00.000Z",
 *     "count": 1304,
 *     "vulnerabilities": [
 *       {
 *         "cveID": "CVE-2024-0001",
 *         "vendorProject": "Microsoft",
 *         "product": "Windows",
 *         "vulnerabilityName": "...",
 *         "dateAdded": "2024-01-15",
 *         "shortDescription": "...",
 *         "requiredAction": "...",
 *         "dueDate": "2024-02-05",
 *         "knownRansomwareCampaignUse": "Known"|"Unknown",
 *         "notes": "...",
 *         "cwes": ["CWE-79"]
 *       }
 *     ]
 *   }
 */
class KevLoader
{
    private string $catalogPath;

    /** Lazy in-memory cache of the parsed catalog (per request). */
    private ?array $catalog = null;

    /** CVE-id → vulnerability index, built on first lookup. */
    private ?array $byCve = null;

    public function __construct(string $projectDir)
    {
        $this->catalogPath = $projectDir . '/resources/data/kev/known_exploited_vulnerabilities.json';
    }

    public function catalogPath(): string
    {
        return $this->catalogPath;
    }

    public function exists(): bool
    {
        return is_file($this->catalogPath);
    }

    /**
     * Returns the full parsed catalog or null if the mirror file doesn't
     * exist yet (i.e., app:kev:refresh hasn't been run).
     */
    public function load(): ?array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }
        if (!$this->exists()) {
            return null;
        }
        $raw = @file_get_contents($this->catalogPath);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $this->catalog = $decoded;
    }

    /** Catalog metadata (everything except the vulnerabilities array). */
    public function meta(): array
    {
        $cat = $this->load();
        if ($cat === null) {
            return [];
        }
        return [
            'title'          => $cat['title'] ?? '',
            'catalogVersion' => $cat['catalogVersion'] ?? '',
            'dateReleased'   => $cat['dateReleased'] ?? '',
            'count'          => (int) ($cat['count'] ?? count($cat['vulnerabilities'] ?? [])),
        ];
    }

    /** All vulnerability entries in the order CISA published them. */
    public function all(): array
    {
        $cat = $this->load();
        return $cat['vulnerabilities'] ?? [];
    }

    /** Look up a single vulnerability by CVE id (case-insensitive). */
    public function byCve(string $cveId): ?array
    {
        $key = strtoupper(trim($cveId));
        if ($key === '') {
            return null;
        }
        if ($this->byCve === null) {
            $this->byCve = [];
            foreach ($this->all() as $entry) {
                $id = strtoupper((string) ($entry['cveID'] ?? ''));
                if ($id !== '') {
                    $this->byCve[$id] = $entry;
                }
            }
        }
        return $this->byCve[$key] ?? null;
    }

    /**
     * Summary stats for the landing page.
     *
     * Tracks the metrics that actually move week-over-week — recent
     * additions and live (not-yet-passed) BOD 22-01 deadlines — rather
     * than calendar-stale counts like "overdue", which would show
     * effectively the entire catalog because most KEV deadlines passed
     * months or years ago.
     *
     *   total            — count of all KEV entries
     *   ransomware       — entries flagged knownRansomwareCampaignUse=Known
     *   added_30d        — dateAdded within the last 30 days
     *   active_deadlines — dueDate is today or in the future (the slice
     *                      where BOD 22-01 still demands action)
     *   added_this_year  — dateAdded since 1 January of current year
     *   top_vendors      — top 10 vendors by entry count
     *   latest_added     — most recent dateAdded across the catalog
     */
    public function summary(): array
    {
        $entries = $this->all();
        $total = count($entries);
        $ransomware = 0;
        $added30d = 0;
        $activeDeadlines = 0;
        $addedThisYear = 0;
        $vendors = [];
        $latestAdded = null;

        $today = new \DateTimeImmutable('today');
        $thirtyDaysAgo = $today->modify('-30 days');
        $startOfYear = new \DateTimeImmutable($today->format('Y') . '-01-01');

        foreach ($entries as $e) {
            if (($e['knownRansomwareCampaignUse'] ?? '') === 'Known') {
                $ransomware++;
            }

            $added = $e['dateAdded'] ?? '';
            if ($added !== '') {
                try {
                    $d = new \DateTimeImmutable($added);
                    if ($d >= $thirtyDaysAgo) {
                        $added30d++;
                    }
                    if ($d >= $startOfYear) {
                        $addedThisYear++;
                    }
                } catch (\Exception $ex) { /* skip */ }
                if ($latestAdded === null || $added > $latestAdded) {
                    $latestAdded = $added;
                }
            }

            $due = $e['dueDate'] ?? '';
            if ($due !== '') {
                try {
                    $d = new \DateTimeImmutable($due);
                    if ($d >= $today) {
                        $activeDeadlines++;
                    }
                } catch (\Exception $ex) { /* skip */ }
            }

            $vendor = (string) ($e['vendorProject'] ?? '');
            if ($vendor !== '') {
                $vendors[$vendor] = ($vendors[$vendor] ?? 0) + 1;
            }
        }
        arsort($vendors);

        return [
            'total'            => $total,
            'ransomware'       => $ransomware,
            'added_30d'        => $added30d,
            'active_deadlines' => $activeDeadlines,
            'added_this_year'  => $addedThisYear,
            'top_vendors'      => array_slice($vendors, 0, 10, true),
            'latest_added'     => $latestAdded,
        ];
    }
}
