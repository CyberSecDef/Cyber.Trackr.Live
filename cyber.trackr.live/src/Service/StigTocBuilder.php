<?php

namespace App\Service;

use Symfony\Component\Finder\Finder;

/**
 * Parses STIG XCCDF XML files into stig_toc.json entries.
 *
 * The toc is a map of normalized title → list of version-instance entries.
 * Each entry holds enough metadata for the homepage and listing pages to
 * render rows without re-parsing the underlying XML, including pre-computed
 * severity counts ({h, m, l}).
 *
 * Two callers:
 *   - StigController::stig() uses parseStig() to add newly-dropped XML files
 *     to the toc on the fly.
 *   - app:stig:rebuild-toc console command uses rebuildAll() to wipe and
 *     reconstruct the toc from scratch (e.g., for a sev-count backfill).
 */
class StigTocBuilder
{
    private string $stigDir;
    private string $tocPath;

    public function __construct(string $projectDir)
    {
        $this->stigDir = $projectDir . '/resources/data/stig';
        $this->tocPath = $projectDir . '/resources/data/stig_toc.json';
    }

    public function tocPath(): string
    {
        return $this->tocPath;
    }

    public function stigDir(): string
    {
        return $this->stigDir;
    }

    /**
     * Parse one STIG XCCDF XML file. Returns ['title' => ..., 'entry' => [...]]
     * or null on failure (file missing, not XML, missing title).
     *
     * The 'entry' array has the same fields the legacy StigController code
     * wrote, plus a new 'sev' sub-array of high/med/low rule counts.
     */
    public function parseStig(string $filePath): ?array
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

        $titleQuery = $xml->xpath('/xmlns:Benchmark/xmlns:title/text()');
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

        $relInfoQuery = $xml->xpath("/xmlns:Benchmark/xmlns:plain-text[@id='release-info']/text()");
        $relInfo = (string) (count($relInfoQuery) > 0 ? $relInfoQuery[0] : '');
        if (preg_match('/Release: ([0-9\.]+) /', $relInfo, $m)) {
            $release = $m[1];
        } else {
            $release = 'UNK';
        }
        $released = trim(explode('Date:', $relInfo)[1] ?? '');

        $dateQuery = $xml->xpath('/xmlns:Benchmark/xmlns:status/@date');
        $date = (string) (count($dateQuery) > 0 ? $dateQuery[0] : '');

        $versionQuery = $xml->xpath('/xmlns:Benchmark/xmlns:version/text()');
        $version = (string) (count($versionQuery) > 0 ? $versionQuery[0] : '');

        return [
            'title' => $title,
            'entry' => [
                'date'     => $date,
                'released' => $released,
                'filename' => basename($filePath),
                'version'  => $version,
                'release'  => $release,
                'sev'      => [
                    'h' => count($xml->xpath("//xmlns:Rule[@severity='high']")),
                    'm' => count($xml->xpath("//xmlns:Rule[@severity='medium']")),
                    'l' => count($xml->xpath("//xmlns:Rule[@severity='low']")),
                ],
            ],
        ];
    }

    /**
     * Walk every *.xml under the STIG data dir and produce a fresh toc.
     * Optional $onFile callback receives each file as it's parsed (for
     * progress display in the console command).
     */
    public function rebuildAll(?callable $onFile = null): array
    {
        $stigs = [];
        $finder = (new Finder())->files()->in($this->stigDir)->name('*.xml');

        foreach ($finder as $file) {
            $parsed = $this->parseStig($file->getRealPath());
            if ($parsed !== null) {
                $stigs[$parsed['title']][] = $parsed['entry'];
            }
            if ($onFile !== null) {
                $onFile($file);
            }
        }

        ksort($stigs);
        return $stigs;
    }

    public function writeToc(array $stigs): void
    {
        file_put_contents(
            $this->tocPath,
            json_encode($stigs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
