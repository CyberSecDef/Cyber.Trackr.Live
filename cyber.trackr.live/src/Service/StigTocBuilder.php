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
 * app:stig:rebuild-toc uses rebuildAll() to wipe and reconstruct the toc
 * from scratch; requests only read it.
 *
 * Each title lists a version + release at most once. DISA sometimes ships
 * one release under two filenames, or re-issues it with edits, so the raw
 * files can disagree; before this every page picked its own row (the view
 * took the last match in directory order, which differed between hosts; the
 * companion docs took the first) — see dedupeTitle().
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
        // Sorted, so the toc (and any tie below) is the same on every host.
        $finder = (new Finder())->files()->in($this->stigDir)->name('*.xml')->sortByName();

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
        foreach ($stigs as $title => $entries) {
            $stigs[$title] = $this->dedupeTitle($entries);
        }
        return $stigs;
    }

    /**
     * Collapse duplicate version + release rows for one title:
     *
     *  1. A file whose NAME says a newer release than its XML declares
     *     (U_Citrix_..._V1R4 whose XML still says 1.3) is listed under the
     *     filename's release, when it shares its declared release with
     *     different content and that release isn't already taken. The XML's
     *     own label is kept as 'xml_release'. Only inside duplicate groups:
     *     elsewhere filenames are wrong as often as the XML (..._V1R78 is 1.8).
     *  2. Of what still shares a release, the newest by benchmark date wins
     *     (then release date, then filename). Byte-identical copies are kept
     *     as 'aliases' so a companion ZIP that ships the other name still
     *     matches; re-issued older content is dropped.
     *
     * @param array<int,array> $entries in filename order
     * @return array<int,array> newest first
     */
    public function dedupeTitle(array $entries): array
    {
        $key = static fn(array $e): string => $e['version'] . '|' . $e['release'];
        $hash = fn(array $e): string => (string) @md5_file($this->stigDir . '/' . $e['filename']);

        $groups = [];
        foreach ($entries as $e) {
            $groups[$key($e)][] = $e;
        }

        // 1. Relabel mislabelled newer releases.
        $taken = array_fill_keys(array_keys($groups), true);
        foreach ($groups as $k => $group) {
            if (count($group) < 2) {
                continue;
            }
            $hashes = array_map($hash, $group);
            foreach ($group as $i => $e) {
                if (!preg_match('/[_-]V(\d+)R(\d+)(?![\d-])/i', $e['filename'], $m)) {
                    continue;
                }
                $named = $m[1] . '|' . $m[2];
                $identical = count(array_keys($hashes, $hashes[$i], true)) > 1;
                if ($named === $k || isset($taken[$named]) || $identical
                    || !version_compare($m[1] . '.' . $m[2], $e['version'] . '.' . $e['release'], '>')) {
                    continue;
                }
                $taken[$named] = true;
                $e['xml_release'] = $e['version'] . '.' . $e['release'];
                $e['version'] = $m[1];
                $e['release'] = $m[2];
                $groups[$named] = [$e];
                unset($groups[$k][$i]);
            }
        }

        // 2. One row per release.
        $out = [];
        foreach ($groups as $group) {
            $group = array_values($group);
            if (count($group) > 1) {
                usort($group, fn($a, $b) => [strtotime((string) $b['date']) ?: 0, $this->sortKey($b), $a['filename']]
                    <=> [strtotime((string) $a['date']) ?: 0, $this->sortKey($a), $b['filename']]);
                $winner = $group[0];
                $winnerHash = $hash($winner);
                $aliases = [];
                foreach (array_slice($group, 1) as $other) {
                    if ($winnerHash !== '' && $hash($other) === $winnerHash) {
                        $aliases[] = $other['filename'];
                    }
                }
                if ($aliases !== []) {
                    $winner['aliases'] = $aliases;
                }
                $group = [$winner];
            }
            $out[] = $group[0];
        }

        // Newest first: by benchmark date, then by version + release. Pages
        // and findPreviousEntry() sort by date alone with a stable sort, so
        // this decides releases that share a date (PowerPoint 2013 V1R2 and
        // V1R3 are both 2014-04-03); directory order used to.
        usort($out, fn($a, $b) => (strtotime((string) $b['date']) ?: 0) <=> (strtotime((string) $a['date']) ?: 0)
            ?: version_compare($b['version'] . '.' . $b['release'], $a['version'] . '.' . $a['release']));
        return $out;
    }

    public function writeToc(array $stigs): void
    {
        $json = json_encode($stigs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || !AtomicFile::write($this->tocPath, $json)) {
            throw new \RuntimeException("Cannot write toc to {$this->tocPath}");
        }
    }

    /**
     * Roll up a toc array (title => [instance, ...]) to a flat list of one
     * record per title, where the chosen instance is the latest by released
     * date. The caller can then slice / sort / render directly. Each record
     * carries the title alongside the entry fields (date, released, version,
     * release, filename, sev).
     */
    public function latestPerTitle(array $stigs): array
    {
        $latest = [];
        foreach ($stigs as $title => $instances) {
            if (empty($instances)) {
                continue;
            }
            usort($instances, fn($a, $b) => $this->sortKey($b) - $this->sortKey($a));
            $latest[] = ['title' => $title] + $instances[0];
        }
        usort($latest, fn($a, $b) => $this->sortKey($b) - $this->sortKey($a));
        return $latest;
    }

    /**
     * Sort key for a stig instance — prefer human "released" string ("24 Jul
     * 2024"), fall back to ISO "date", fall back to 0 so entries with neither
     * sink rather than bubble.
     */
    private function sortKey(array $entry): int
    {
        $rel = trim((string) ($entry['released'] ?? ''));
        if ($rel !== '') {
            $ts = strtotime($rel);
            if ($ts !== false) {
                return $ts;
            }
        }
        $date = (string) ($entry['date'] ?? '');
        if ($date !== '') {
            $ts = strtotime($date);
            if ($ts !== false) {
                return $ts;
            }
        }
        return 0;
    }
}
