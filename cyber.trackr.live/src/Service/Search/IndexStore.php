<?php

namespace App\Service\Search;

use App\Service\AtomicFile;

/**
 * Reads and writes the search index under resources/data/search/. Plain
 * files only — no database, no cache layer.
 *
 * Each build is an immutable directory; a one-line `current` file names the
 * live one and is swapped atomically, so a request never sees postings from
 * one build and docs from another:
 *
 *   current                      - name of the live build directory
 *   build-<stamp>/
 *     postings/XX.json           - {token: [doc_idx, ...]} for tokens whose
 *                                  md5 starts with XX (256 buckets)
 *     trigrams/XX.json           - {trigram: [token, ...]}, same bucketing
 *     docs.ndjson                - one JSON doc per line (null = dropped)
 *     docmeta.bin                - per doc, fixed 19 bytes: byte offset and
 *                                  length in docs.ndjson, type code,
 *                                  release timestamp, title index
 *     titles.json                - STIG titles referenced by docmeta
 *     sources.json               - {source_path: {sha256, mtime,
 *                                  doc_indexes}} for IndexBuilder deltas
 *
 * A query reads only the buckets for its tokens (review P2: the old single
 * postings.json was 27 MB and cost ~190 ms / ~300 MB on every search), ranks
 * matches from docmeta without touching documents, and then reads just the
 * documents it will show, each with one fseek/fread.
 */
class IndexStore
{
    public const TYPES = ['rmfv4' => 1, 'rmfv5' => 2, 'ccis' => 3, 'aps' => 4, 'vulns' => 5];

    private const POINTER = 'current';
    private const META_FORMAT = 'Voffset/Vlength/Ctype/qts/vtitle';
    private const META_SIZE = 19; // V4 + V4 + C1 + q8 + v2

    /** Pre-build-directory layout, removed after the first new build. */
    private const LEGACY = ['postings.json', 'trigrams.json', 'sources.json', 'docs'];

    private string $dir;

    // Per-request memo of the build in use (resolved once per instance).
    private ?string $build = null;
    private ?string $meta = null;
    /** @var list<string>|null */
    private ?array $titles = null;
    /** @var resource|null */
    private $docsHandle = null;

    public function __construct(string $projectDir)
    {
        $this->dir = $projectDir . '/resources/data/search';
    }

    public function __destruct()
    {
        if (is_resource($this->docsHandle)) {
            fclose($this->docsHandle);
        }
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function exists(): bool
    {
        return $this->build() !== null;
    }

    /** Name of the live build directory, or null if there is none. */
    public function currentBuild(): ?string
    {
        $build = $this->build();
        return $build === null ? null : basename($build);
    }

    /**
     * Posting lists for the given tokens, reading only their buckets.
     * Tokens with no postings are absent from the result.
     *
     * @param list<string|int> $tokens
     * @return array<string|int, list<int>>
     */
    public function postingsFor(array $tokens): array
    {
        return $this->lookup('postings', $tokens);
    }

    /**
     * @param list<string> $trigrams
     * @return array<string, list<string>>
     */
    public function trigramsFor(array $trigrams): array
    {
        return $this->lookup('trigrams', $trigrams);
    }

    /** Every posting list (IndexBuilder deltas only). */
    public function allPostings(): array
    {
        return $this->allBuckets('postings');
    }

    public function sources(): array
    {
        $build = $this->build();
        return $build === null ? [] : $this->readJson($build . '/sources.json');
    }

    public function docCount(): int
    {
        return intdiv(strlen($this->metaBytes()), self::META_SIZE);
    }

    /**
     * Type, release timestamp and title of a doc, without reading it.
     *
     * @return array{type: ?string, ts: int, title: string}
     */
    public function meta(int $idx): array
    {
        static $names = null;
        $names ??= array_flip(self::TYPES);
        $raw = substr($this->metaBytes(), $idx * self::META_SIZE, self::META_SIZE);
        if (strlen($raw) !== self::META_SIZE) {
            return ['type' => null, 'ts' => 0, 'title' => ''];
        }
        $m = unpack(self::META_FORMAT, $raw);
        return [
            'type'  => $names[$m['type']] ?? null,
            'ts'    => $m['ts'],
            // Only vulns carry a title; others store index 0 as filler.
            'title' => $m['type'] === self::TYPES['vulns'] ? ($this->titles()[$m['title']] ?? '') : '',
        ];
    }

    /**
     * @param list<int> $indexes
     * @return array<int, array> doc index => doc record (dropped docs omitted)
     */
    public function getDocs(array $indexes): array
    {
        $meta = $this->metaBytes();
        $located = [];
        foreach ($indexes as $idx) {
            $raw = substr($meta, $idx * self::META_SIZE, self::META_SIZE);
            if (strlen($raw) !== self::META_SIZE) continue;
            $m = unpack(self::META_FORMAT, $raw);
            if ($m['length'] > 0) $located[$idx] = $m;
        }
        if (!$located) return [];

        // Read in file order; return in the order asked for.
        $byOffset = $located;
        uasort($byOffset, fn($a, $b) => $a['offset'] <=> $b['offset']);
        $fh = $this->docsHandle();
        $read = [];
        foreach ($byOffset as $idx => $m) {
            fseek($fh, $m['offset']);
            $doc = json_decode((string) fread($fh, $m['length']), true);
            if (is_array($doc)) $read[$idx] = $doc;
        }
        $out = [];
        foreach ($indexes as $idx) {
            if (isset($read[$idx])) $out[$idx] = $read[$idx];
        }
        return $out;
    }

    /** Every doc, nulls included, in index order (IndexBuilder deltas only). */
    public function loadAllDocs(): array
    {
        $build = $this->build();
        if ($build === null || !is_file($build . '/docs.ndjson')) return [];
        $docs = [];
        $fh = fopen($build . '/docs.ndjson', 'rb');
        while (($line = fgets($fh)) !== false) {
            $docs[] = json_decode($line, true);
        }
        fclose($fh);
        return $docs;
    }

    /**
     * Write a complete build to a fresh directory, then make it live.
     *
     * @param list<array|null> $docs
     * @return string the new build directory's name
     */
    public function writeBuild(array $docs, array $postings, array $trigrams, array $sources): string
    {
        if (!is_dir($this->dir)) @mkdir($this->dir, 0755, true);
        $name = 'build-' . date('Ymd\THis') . '-' . bin2hex(random_bytes(3));
        $path = $this->dir . '/' . $name;
        if (!@mkdir($path, 0755) || !@mkdir($path . '/postings', 0755) || !@mkdir($path . '/trigrams', 0755)) {
            throw new \RuntimeException("Could not create $path");
        }

        $this->writeBuckets($path . '/postings', $postings);
        $this->writeBuckets($path . '/trigrams', $trigrams);

        // docs.ndjson + docmeta.bin, written in one pass.
        $titles = [];
        $fh = fopen($path . '/docs.ndjson', 'wb');
        $meta = '';
        $offset = 0;
        foreach ($docs as $doc) {
            $line = json_encode($doc, JSON_UNESCAPED_SLASHES);
            if ($line === false) {
                fclose($fh);
                throw new \RuntimeException('Could not encode a search doc: ' . json_last_error_msg());
            }
            fwrite($fh, $line . "\n");
            $type = is_array($doc) ? (self::TYPES[$doc['type'] ?? ''] ?? 0) : 0;
            $ts = 0;
            $titleIdx = 0;
            if ($type === self::TYPES['vulns']) {
                // Same key the old request-time sort computed with strtotime().
                $ts = strtotime((string) ($doc['released'] ?? '')) ?: 0;
                $title = (string) ($doc['stig_title'] ?? '');
                $titles[$title] ??= count($titles);
                $titleIdx = $titles[$title];
            }
            $meta .= pack('VVCqv', $offset, is_array($doc) ? strlen($line) : 0, $type, $ts, $titleIdx);
            $offset += strlen($line) + 1;
        }
        fclose($fh);
        if (count($titles) > 65535) {
            throw new \RuntimeException('Too many STIG titles for docmeta (max 65535)');
        }
        $this->writeFile($path . '/docmeta.bin', $meta);
        $this->writeFile($path . '/titles.json', (string) json_encode(array_keys($titles), JSON_UNESCAPED_SLASHES));
        $this->writeFile($path . '/sources.json', (string) json_encode($sources, JSON_UNESCAPED_SLASHES));

        if (!AtomicFile::write($this->dir . '/' . self::POINTER, $name . "\n")) {
            throw new \RuntimeException('Could not switch the search index to ' . $name);
        }
        $this->resetMemo();
        $this->prune($name);

        return $name;
    }

    /** Keep the live build and the one before it (for requests in flight). */
    private function prune(string $live): void
    {
        $builds = glob($this->dir . '/build-*', GLOB_ONLYDIR) ?: [];
        rsort($builds); // names sort by timestamp
        $keep = [$this->dir . '/' . $live];
        foreach ($builds as $b) {
            if (count($keep) < 2 && !in_array($b, $keep, true)) $keep[] = $b;
        }
        foreach ($builds as $b) {
            if (!in_array($b, $keep, true)) $this->removeTree($b);
        }
        foreach (self::LEGACY as $old) {
            $p = $this->dir . '/' . $old;
            is_dir($p) ? $this->removeTree($p) : @unlink($p);
        }
    }

    public static function bucket(string|int $key): string
    {
        return substr(md5((string) $key), 0, 2);
    }

    private function lookup(string $kind, array $keys): array
    {
        $build = $this->build();
        if ($build === null || !$keys) return [];
        $byBucket = [];
        foreach ($keys as $k) {
            $byBucket[self::bucket($k)][] = $k;
        }
        $out = [];
        foreach ($byBucket as $bucket => $wanted) {
            $data = $this->readJson("$build/$kind/$bucket.json");
            foreach ($wanted as $k) {
                if (isset($data[$k])) $out[$k] = $data[$k];
            }
        }
        return $out;
    }

    private function allBuckets(string $kind): array
    {
        $build = $this->build();
        if ($build === null) return [];
        $all = [];
        foreach (glob("$build/$kind/*.json") ?: [] as $f) {
            $all += $this->readJson($f);
        }
        return $all;
    }

    private function writeBuckets(string $dir, array $map): void
    {
        $buckets = [];
        foreach ($map as $key => $value) {
            $buckets[self::bucket($key)][$key] = $value;
        }
        foreach ($buckets as $bucket => $data) {
            $json = json_encode($data, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new \RuntimeException("Could not encode bucket $bucket: " . json_last_error_msg());
            }
            $this->writeFile("$dir/$bucket.json", $json);
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        if (@file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Could not write $path");
        }
    }

    private function build(): ?string
    {
        if ($this->build === null) {
            $name = trim((string) @file_get_contents($this->dir . '/' . self::POINTER));
            if ($name !== '' && preg_match('/^build-[A-Za-z0-9-]+$/', $name) && is_dir($this->dir . '/' . $name)) {
                $this->build = $this->dir . '/' . $name;
            }
        }
        return $this->build;
    }

    private function metaBytes(): string
    {
        if ($this->meta === null) {
            $build = $this->build();
            $this->meta = $build === null ? '' : (string) @file_get_contents($build . '/docmeta.bin');
        }
        return $this->meta;
    }

    /** @return list<string> */
    private function titles(): array
    {
        if ($this->titles === null) {
            $build = $this->build();
            $this->titles = $build === null ? [] : $this->readJson($build . '/titles.json');
        }
        return $this->titles;
    }

    /** @return resource */
    private function docsHandle()
    {
        if (!is_resource($this->docsHandle)) {
            $h = @fopen($this->build() . '/docs.ndjson', 'rb');
            if ($h === false) {
                throw new \RuntimeException('Search index docs are missing; run app:search:rebuild --full');
            }
            $this->docsHandle = $h;
        }
        return $this->docsHandle;
    }

    private function resetMemo(): void
    {
        if (is_resource($this->docsHandle)) fclose($this->docsHandle);
        $this->build = $this->meta = $this->titles = $this->docsHandle = null;
    }

    private function readJson(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($path);
    }
}
