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
        $finder = (new Finder())->files()->in($this->scapDir)->name('*.xml');

        foreach ($finder as $file) {
            $parsed = $this->parseScap($file->getRealPath());
            if ($parsed !== null) {
                $scaps[$parsed['title']][] = $parsed['entry'];
            }
            if ($onFile !== null) {
                $onFile($file);
            }
        }

        ksort($scaps);
        return $scaps;
    }

    public function writeToc(array $scaps): void
    {
        file_put_contents(
            $this->tocPath,
            json_encode($scaps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
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
