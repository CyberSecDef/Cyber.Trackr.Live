<?php

namespace App\Service;

use Symfony\Component\Finder\Finder;
use ZipArchive;

/**
 * Locates the DISA companion ZIP for a given STIG title/version/release and
 * enumerates the PDF entries inside it (overview, revision history, readme,
 * etc.). The view template uses this to surface supplemental docs alongside
 * the rules.
 *
 * Matching is *content-based*, not filename-based: DISA's ZIP filenames are
 * inconsistent (e.g. "U_ASD_V6R4_STIG.zip" for "Application Security and
 * Development"), so we open each ZIP once and key on the XCCDF XML filename
 * inside, which DOES line up with the stig_toc.json filename. The lookup
 * table lives in resources/data/companion_zip_index.json and is rebuilt by
 * `app:companion-zip:rebuild-index` (wired into bin/refresh-data.sh).
 */
class StigCompanionZipFinder
{
    private string $zipDir;
    private string $indexPath;
    private string $tocPath;

    /** @var array<string,string>|null xml-basename → zip-basename */
    private ?array $index = null;

    public function __construct(string $projectDir)
    {
        $this->zipDir    = $projectDir . '/resources/data/zips';
        $this->indexPath = $projectDir . '/resources/data/companion_zip_index.json';
        $this->tocPath   = $projectDir . '/resources/data/stig_toc.json';
    }

    public function indexPath(): string
    {
        return $this->indexPath;
    }

    /**
     * Resolve metadata for the companion ZIP, including its PDF entries.
     * Returns null when no companion ZIP is indexed for this STIG.
     */
    /**
     * @param array<int,array|object>|null $tocRows this title's stig_toc.json
     *        rows, when the caller has already decoded the TOC (review P15:
     *        the STIG view used to decode the 1.4 MB file twice)
     */
    public function find(string $title, string $version, string $release, ?array $tocRows = null): ?array
    {
        $path = $this->resolveZipPath($title, $version, $release, $tocRows);
        if ($path === null || !is_file($path)) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        $pdfs = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $name = $stat['name'];
            if (substr($name, -1) === '/') {
                continue;
            }
            if (strcasecmp(pathinfo($name, PATHINFO_EXTENSION), 'pdf') !== 0) {
                continue;
            }
            $pdfs[] = [
                'name'     => $name,
                'basename' => basename($name),
                'size'     => (int) $stat['size'],
            ];
        }
        $zip->close();

        usort($pdfs, fn($a, $b) => strnatcasecmp($a['basename'], $b['basename']));

        return [
            'path'     => $path,
            'basename' => basename($path),
            'size'     => filesize($path),
            'pdfs'     => $pdfs,
        ];
    }

    /**
     * Resolve the on-disk ZIP path for a STIG via the index. Validates the
     * route params so attacker-supplied titles can't be used to probe the
     * filesystem, and validates the indexed basename so a corrupted index
     * file can't redirect us outside the zips directory.
     */
    /** @param array<int,array|object>|null $tocRows see find() */
    public function resolveZipPath(string $title, string $version, string $release, ?array $tocRows = null): ?string
    {
        if (!$this->validParams($title, $version, $release)) {
            return null;
        }
        $xmlName = $this->lookupXmlFilename($title, $version, $release, $tocRows);
        if ($xmlName === null) {
            return null;
        }
        $index = $this->loadIndex();
        $zipBase = $index[$xmlName] ?? null;
        if ($zipBase === null) {
            return null;
        }
        // Defense in depth: only trust a bare filename, no directory parts.
        if ($zipBase !== basename($zipBase) || !preg_match('/^U_.+\.zip$/i', $zipBase)) {
            return null;
        }
        return $this->zipDir . '/' . $zipBase;
    }

    /**
     * Open the companion ZIP and return the bytes of the named PDF entry,
     * or null if the ZIP, entry, or extension is invalid. The entry name
     * must match exactly something listed inside the resolved archive; we
     * re-validate against the live entry list to defeat path-traversal.
     */
    public function readPdfEntry(string $title, string $version, string $release, string $entry): ?array
    {
        if (strcasecmp(pathinfo($entry, PATHINFO_EXTENSION), 'pdf') !== 0) {
            return null;
        }
        if (str_contains($entry, '..')) {
            return null;
        }

        $path = $this->resolveZipPath($title, $version, $release);
        if ($path === null || !is_file($path)) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        $index = $zip->locateName($entry);
        if ($index === false) {
            $zip->close();
            return null;
        }
        $bytes = $zip->getFromIndex($index);
        $zip->close();
        if ($bytes === false) {
            return null;
        }

        return [
            'basename' => basename($entry),
            'bytes'    => $bytes,
        ];
    }

    /**
     * Walk every ZIP in resources/data/zips/, look for an XCCDF XML inside
     * that matches a stig_toc.json entry, and write the resulting map to
     * companion_zip_index.json. Returns counters for command output.
     *
     * Counters:
     *   - zips_scanned   : total ZIP files inspected
     *   - zips_matched   : ZIPs that contained at least one known STIG XML
     *   - zips_unmatched : ZIPs with no STIG XML (SCAP/Ansible/Overview etc.)
     *   - stigs_total    : distinct STIG versions in the toc
     *   - stigs_covered  : STIG versions now resolvable to a companion ZIP
     */
    public function rebuildIndex(): array
    {
        $tocRaw = file_get_contents($this->tocPath);
        if ($tocRaw === false) {
            throw new \RuntimeException("Cannot read STIG toc at {$this->tocPath}");
        }
        $toc = json_decode($tocRaw, true) ?: [];

        // Build set of canonical XCCDF XML filenames from the toc.
        $known = [];
        foreach ($toc as $title => $rows) {
            foreach ($rows as $row) {
                if (isset($row['filename'])) {
                    $known[$row['filename']] = true;
                }
            }
        }

        $map = [];
        $scanned = 0;
        $matched = 0;
        $unmatched = 0;

        $finder = (new Finder())->files()->in($this->zipDir)->name('*.zip');
        foreach ($finder as $file) {
            $scanned++;
            $zip = new ZipArchive();
            if ($zip->open($file->getRealPath(), ZipArchive::RDONLY) !== true) {
                $unmatched++;
                continue;
            }
            $hit = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = basename($zip->getNameIndex($i));
                if (isset($known[$name])) {
                    $hit = true;
                    if (!isset($map[$name])) {
                        $map[$name] = $file->getFilename();
                    }
                }
            }
            $zip->close();
            $hit ? $matched++ : $unmatched++;
        }

        ksort($map, SORT_NATURAL | SORT_FLAG_CASE);

        $json = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (!AtomicFile::write($this->indexPath, $json)) {
            throw new \RuntimeException("Cannot write companion index to {$this->indexPath}");
        }
        $this->index = $map;

        return [
            'zips_scanned'   => $scanned,
            'zips_matched'   => $matched,
            'zips_unmatched' => $unmatched,
            'stigs_total'    => count($known),
            'stigs_covered'  => count($map),
        ];
    }

    /**
     * Cheap sanity check before the toc lookup. These values are only ever
     * used as toc keys (the file path comes from the index's bare basename),
     * so the sets match what toc titles and versions actually contain: the
     * old [A-Za-z0-9_-] title / digits-only rule hid companion docs for 492
     * STIG versions, e.g. APACHE_2.2_Server_for_UNIX, Apple_macOS_15_(Sequoia),
     * BIND_9.x V3R0.1.
     */
    private function validParams(string $title, string $version, string $release): bool
    {
        if (!preg_match('/^[A-Za-z0-9_\-.&(),:]{1,200}$/', $title)) return false;
        if (!preg_match('/^[A-Za-z0-9.]{1,20}$/', $version))       return false;
        if (!preg_match('/^[A-Za-z0-9.]{1,20}$/', $release))       return false;
        return true;
    }

    /**
     * The FIRST row matching version + release exactly. A few titles list the
     * same release twice under different XML filenames; the companion routes
     * all resolve through here, so the view page must use the same rule.
     */
    private function lookupXmlFilename(string $title, string $version, string $release, ?array $tocRows = null): ?string
    {
        if ($tocRows === null) {
            $raw = @file_get_contents($this->tocPath);
            if ($raw === false) return null;
            $toc = json_decode($raw, true);
            if (!is_array($toc) || !isset($toc[$title])) return null;
            $tocRows = $toc[$title];
        }
        foreach ($tocRows as $row) {
            $row = (array) $row;
            if ((string)($row['version'] ?? '') === $version && (string)($row['release'] ?? '') === $release) {
                return $row['filename'] ?? null;
            }
        }
        return null;
    }

    private function loadIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        if (!is_file($this->indexPath)) {
            return $this->index = [];
        }
        $raw = file_get_contents($this->indexPath);
        $data = json_decode($raw ?: '[]', true);
        return $this->index = is_array($data) ? $data : [];
    }
}
