<?php

namespace App\Service;

use Symfony\Component\Finder\Finder;

/**
 * Parses SCAP XCCDF source XML files into scap_toc.json entries.
 *
 * Mirrors StigTocBuilder. Same shape: title => list of version-instances.
 * Each entry holds enough metadata for the listing pages to render rows
 * without re-parsing the underlying XML, including pre-computed severity
 * counts ({h, m, l}) so the /scap list can show a Severity Mix column
 * the same way /stig does.
 *
 * Two callers:
 *   - ScapController::scap() uses parseScap() to add newly-dropped XML
 *     files to the toc on the fly.
 *   - app:scap:rebuild-toc console command uses rebuildAll() for the
 *     one-time sev-count backfill (and any future schema bump).
 */
class ScapTocBuilder
{
    private string $scapDir;
    private string $tocPath;

    /**
     * Until V6R4, DISA published Firefox's Linux/RHEL and Windows SCAP
     * benchmarks under one title ("Mozilla Firefox"; V6R3 Windows even under
     * "for Linux"), so one title held both platforms for a release and the
     * page could only show one. Every Firefox SCAP file is re-filed, by the
     * platform in its filename, into the titles DISA has used since V6R4.
     */
    private const PLATFORM_SPLIT_TITLES = [
        'Mozilla_FireFox',
        'Mozilla_Firefox',
        'Mozilla_Firefox_for_Linux_STIG_SCAP_Benchmark',
        'Mozilla_Firefox_for_Windows_STIG_SCAP_Benchmark',
    ];
    private const PLATFORM_TITLE = 'Mozilla_Firefox_for_%s_STIG_SCAP_Benchmark';

    /** Titles that no longer exist after the split; old links redirect (platformRedirect()). */
    private const RETIRED_TITLES = ['Mozilla_FireFox', 'Mozilla_Firefox'];

    public function __construct(string $projectDir)
    {
        $this->scapDir = $projectDir . '/resources/data/scap';
        $this->tocPath = $projectDir . '/resources/data/scap_toc.json';
    }

    public function tocPath(): string
    {
        return $this->tocPath;
    }

    public function scapDir(): string
    {
        return $this->scapDir;
    }

    /**
     * Parse one SCAP XCCDF XML file. Returns ['title' => ..., 'entry' => [...]]
     * or null on failure. The legacy ScapController parsing logic is preserved
     * verbatim — title normalization, release-info regex, version/release
     * extraction — so existing toc keys keep matching new entries.
     */
    public function parseScap(string $filePath): ?array
    {
        if (!is_file($filePath) || pathinfo($filePath, PATHINFO_EXTENSION) !== 'xml') {
            return null;
        }

        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            return null;
        }

        foreach ($xml->getDocNamespaces() as $prefix => $ns) {
            if ((string) $prefix === '') {
                $prefix = 'a';
            }
            $xml->registerXPathNamespace($prefix, $ns);
        }
        $xml->registerXPathNamespace('xmlns', 'http://checklists.nist.gov/xccdf/1.1');
        $xml->registerXPathNamespace('xccdf', 'http://checklists.nist.gov/xccdf/1.2');
        $xml->registerXPathNamespace('dict', 'http://cpe.mitre.org/dictionary/2.0');
        $xml->registerXPathNamespace('aaa', 'http://scap.nist.gov/schema/scap/source/1.2');

        $titleQuery = $xml->xpath('//xccdf:Benchmark/xccdf:title/text()');
        $title = (string) (count($titleQuery) > 0 ? $titleQuery[0] : '');
        $title = str_replace(
            ['(STIG)', 'Security Technical Implementation Guide', ' MS ', 'Microsoft', '\/', '\\', '/'],
            '',
            $title
        );
        $title = trim($title);
        $title = str_replace(' ', '_', $title);
        if ($title === '') {
            return null;
        }

        $dateQuery = $xml->xpath('//xccdf:Benchmark/xccdf:status/@date');
        $date = (string) (count($dateQuery) > 0 ? $dateQuery[0] : '');

        $version = '';
        $release = '';

        $relInfoQuery = $xml->xpath("//xccdf:Benchmark/xccdf:plain-text[@id='release-info']/text()");
        if (count($relInfoQuery) > 0) {
            $relInfo = (string) $relInfoQuery[0];
            if (preg_match('/Release: ([0-9\.]+) /', $relInfo, $m)) {
                $vr = explode('.', $m[1]);
                $version = $vr[0] ?? '';
                $release = $vr[1] ?? '';
            }
        }

        if ($release === '' || $version === '') {
            $verQuery = $xml->xpath('//xccdf:Benchmark/xccdf:version/text()');
            if (count($verQuery) > 0) {
                $parts = explode('.', (string) $verQuery[0]);
                $version = isset($parts[0]) ? trim((string) (int) $parts[0]) : '';
                $release = isset($parts[1]) ? trim((string) (int) $parts[1]) : '';
            }
        }

        if ($version === '' || $release === '') {
            return null;
        }

        return [
            'title' => $title,
            'entry' => [
                'date'     => $date,
                'filename' => basename($filePath),
                'version'  => $version,
                'release'  => $release,
                'sev'      => [
                    'h' => count($xml->xpath("//xccdf:Rule[@severity='high']")),
                    'm' => count($xml->xpath("//xccdf:Rule[@severity='medium']")),
                    'l' => count($xml->xpath("//xccdf:Rule[@severity='low']")),
                ],
            ],
        ];
    }

    /**
     * Re-scan every SCAP XCCDF XML in the data dir and produce a fresh toc.
     * Optional $onFile callback receives each file as it's parsed.
     */
    public function rebuildAll(?callable $onFile = null): array
    {
        $scaps = [];
        // Sorted, so the toc is the same on every host.
        $finder = (new Finder())->files()->in($this->scapDir)->name('*.xml')->sortByName();

        foreach ($finder as $file) {
            $parsed = $this->parseScap($file->getRealPath());
            if ($parsed !== null) {
                $scaps[$this->platformTitle($parsed['title'], $parsed['entry']['filename'])][] = $parsed['entry'];
            }
            if ($onFile !== null) {
                $onFile($file);
            }
        }

        ksort($scaps);
        // Newest first: by date, then by version + release, so releases that
        // share a date (Windows 10 V3R4 and V3R5 are both 2025-02-25) list in
        // release order; the version list sorts by date alone.
        // Then filename, so the order is the same on every host.
        foreach ($scaps as $title => $entries) {
            usort($entries, fn($a, $b) => (strtotime((string) $b['date']) ?: 0) <=> (strtotime((string) $a['date']) ?: 0)
                ?: version_compare($b['version'] . '.' . $b['release'], $a['version'] . '.' . $a['release'])
                ?: strcmp((string) $a['filename'], (string) $b['filename']));
            $scaps[$title] = $entries;
        }
        return $scaps;
    }

    /** The title a parsed file is listed under (see PLATFORM_SPLIT_TITLES). */
    private function platformTitle(string $title, string $filename): string
    {
        if (!in_array($title, self::PLATFORM_SPLIT_TITLES, true)) {
            return $title;
        }
        if (preg_match('/_(Linux|RHEL)_/i', $filename)) {
            return sprintf(self::PLATFORM_TITLE, 'Linux');
        }
        if (preg_match('/_Windows_/i', $filename)) {
            return sprintf(self::PLATFORM_TITLE, 'Windows');
        }
        return $title;
    }

    /**
     * For a link to a title retired by the platform split, the title that now
     * holds that release: Linux first (the variant those pages showed), then
     * Windows. Null when it isn't a retired title or no platform has it.
     *
     * @param array<string, array<int,array|object>> $scaps the decoded toc
     */
    public function platformRedirect(string $title, string $version, string $release, array $scaps): ?string
    {
        if (!in_array($title, self::RETIRED_TITLES, true)) {
            return null;
        }
        foreach (['Linux', 'Windows'] as $platform) {
            $target = sprintf(self::PLATFORM_TITLE, $platform);
            foreach ($scaps[$target] ?? [] as $row) {
                $row = (array) $row;
                if ((string) $row['version'] === $version && (string) $row['release'] === $release) {
                    return $target;
                }
            }
        }
        return null;
    }

    public function writeToc(array $scaps): void
    {
        $json = json_encode($scaps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || !AtomicFile::write($this->tocPath, $json)) {
            throw new \RuntimeException("Cannot write toc to {$this->tocPath}");
        }
    }

    /**
     * Roll up to one record per title with the latest version-instance.
     * Sort key is the entry's `date` (no `released` field on SCAP entries).
     */
    public function latestPerTitle(array $scaps): array
    {
        $latest = [];
        foreach ($scaps as $title => $instances) {
            if (empty($instances)) {
                continue;
            }
            usort($instances, fn($a, $b) => strtotime((string) ($b['date'] ?? '')) - strtotime((string) ($a['date'] ?? '')));
            $latest[] = ['title' => $title] + $instances[0];
        }
        usort($latest, fn($a, $b) => strtotime((string) ($b['date'] ?? '')) - strtotime((string) ($a['date'] ?? '')));
        return $latest;
    }
}
