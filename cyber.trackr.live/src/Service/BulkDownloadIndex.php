<?php

namespace App\Service;

/**
 * Per-row presence + size data for the bulk-download table on /stig/bulk.
 *
 * The sidecar JSON file lists every STIG version in the toc with the
 * corresponding XML path/size and (when available) the companion ZIP
 * basename and size. Rendering the bulk table without this would mean
 * filesize()/file_exists() on ~8000 files per request; the sidecar lets
 * us answer those questions for free at request time.
 *
 * Rebuilt by app:bulk-download:rebuild-index, which is invoked from
 * bin/refresh-data.sh alongside the companion-zip index.
 */
class BulkDownloadIndex
{
    private string $projectDir;
    private string $indexPath;
    private string $tocPath;
    private string $stigDir;
    private string $zipDir;
    private string $companionIndexPath;

    /** @var array<int,array<string,mixed>>|null */
    private ?array $entries = null;
    /** @var array<string,int>|null id => offset into entries */
    private ?array $byId = null;

    public function __construct(string $projectDir)
    {
        $this->projectDir         = $projectDir;
        $this->indexPath          = $projectDir . '/resources/data/bulk_download_index.json';
        $this->tocPath            = $projectDir . '/resources/data/stig_toc.json';
        $this->stigDir            = $projectDir . '/resources/data/stig';
        $this->zipDir             = $projectDir . '/resources/data/zips';
        $this->companionIndexPath = $projectDir . '/resources/data/companion_zip_index.json';
    }

    public function indexPath(): string { return $this->indexPath; }
    public function stigDir(): string   { return $this->stigDir; }
    public function zipDir(): string    { return $this->zipDir; }

    /**
     * Composite key used on the wire (URL-safe, no slashes). Reversible
     * via splitId().
     */
    public static function makeId(string $title, string $version, string $release): string
    {
        return sprintf('%s__V%sR%s', $title, $version, $release);
    }

    /**
     * @return array{0:string,1:string,2:string}|null
     *
     * Title charset matches what actually appears in stig_toc.json keys:
     * alphanumerics plus `_ - . & ( ) , :`. Versions are digits;
     * releases are digits with an optional .N suffix (e.g. V1R0.1).
     * The real authorization happens in BulkDownloadIndex::get() —
     * an unknown ID won't resolve to a file.
     */
    public static function splitId(string $id): ?array
    {
        if (!preg_match('/^([A-Za-z0-9_\-.&(),:]+)__V(\d+)R(\d+(?:\.\d+)?)$/', $id, $m)) {
            return null;
        }
        return [$m[1], $m[2], $m[3]];
    }

    /** @return array<int,array<string,mixed>> */
    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }
        if (!is_file($this->indexPath)) {
            return $this->entries = [];
        }
        $raw = file_get_contents($this->indexPath);
        $data = json_decode($raw ?: '[]', true);
        $this->entries = is_array($data) ? $data : [];
        $this->byId = [];
        foreach ($this->entries as $idx => $row) {
            if (isset($row['id'])) {
                $this->byId[$row['id']] = $idx;
            }
        }
        return $this->entries;
    }

    public function get(string $id): ?array
    {
        $this->entries(); // ensure loaded
        if ($this->byId === null || !isset($this->byId[$id])) {
            return null;
        }
        return $this->entries[$this->byId[$id]];
    }

    /**
     * Walks stig_toc.json + companion_zip_index.json, stat()s each file,
     * writes the sidecar. Returns counters for the console command.
     */
    public function rebuild(): array
    {
        $tocRaw = file_get_contents($this->tocPath);
        if ($tocRaw === false) {
            throw new \RuntimeException("Cannot read STIG toc at {$this->tocPath}");
        }
        $toc = json_decode($tocRaw, true) ?: [];

        $companionRaw = is_file($this->companionIndexPath) ? file_get_contents($this->companionIndexPath) : '[]';
        $companion = json_decode($companionRaw ?: '[]', true) ?: [];

        $entries = [];
        $xmlPresent = 0;
        $zipPresent = 0;
        $xmlBytes = 0;
        $zipBytes = 0;

        foreach ($toc as $title => $rows) {
            foreach ($rows as $row) {
                $version = (string)($row['version'] ?? '');
                $release = (string)($row['release'] ?? '');
                $xmlName = $row['filename'] ?? null;
                if ($xmlName === null || $version === '' || $release === '') {
                    continue;
                }
                $xmlPath = $this->stigDir . '/' . $xmlName;
                $xmlSize = is_file($xmlPath) ? (int) filesize($xmlPath) : 0;
                if ($xmlSize > 0) {
                    $xmlPresent++;
                    $xmlBytes += $xmlSize;
                }

                $zipName = $companion[$xmlName] ?? null;
                $zipSize = 0;
                if ($zipName !== null) {
                    $zipPath = $this->zipDir . '/' . $zipName;
                    $zipSize = is_file($zipPath) ? (int) filesize($zipPath) : 0;
                }
                if ($zipSize > 0) {
                    $zipPresent++;
                    $zipBytes += $zipSize;
                }

                $entries[] = [
                    'id'           => self::makeId($title, $version, $release),
                    'title'        => $title,
                    'version'      => $version,
                    'release'      => $release,
                    'date'         => $row['date'] ?? '',
                    'released'     => $row['released'] ?? '',
                    'xml_filename' => $xmlName,
                    'xml_size'     => $xmlSize,
                    'zip_filename' => $zipSize > 0 ? $zipName : null,
                    'zip_size'     => $zipSize,
                ];
            }
        }

        // Sort by title then version desc then release desc — matches the
        // canonical ordering the bulk table uses by default.
        usort($entries, function ($a, $b) {
            $t = strnatcasecmp($a['title'], $b['title']);
            if ($t !== 0) return $t;
            $v = ((int)$b['version']) <=> ((int)$a['version']);
            if ($v !== 0) return $v;
            return ((int)$b['release']) <=> ((int)$a['release']);
        });

        $json = json_encode($entries, JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($this->indexPath, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write bulk-download index to {$this->indexPath}");
        }
        $this->entries = $entries;
        $this->byId = null;

        return [
            'entries'      => count($entries),
            'xml_present'  => $xmlPresent,
            'zip_present'  => $zipPresent,
            'xml_bytes'    => $xmlBytes,
            'zip_bytes'    => $zipBytes,
        ];
    }
}
