<?php

namespace App\Service\Search;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Loads and saves the search index files under resources/data/search/.
 *
 *   postings.json   - {token: [doc_idx, doc_idx, ...]} sorted, deduped.
 *   trigrams.json   - {trigram: [token, ...]} for fuzzy fallback.
 *   sources.json    - {source_path: {sha256, mtime, doc_indexes: [...]}}
 *                     used by IndexBuilder for delta detection.
 *   docs/####.json  - sharded docs, SHARD_SIZE per file. Each file is
 *                     a 0-based array; doc index N lives at local
 *                     position N % SHARD_SIZE in shard floor(N/SHARD_SIZE).
 *
 * Sharding lets a search load only the shard slices that actually
 * contain matched docs (~30 MB resident on a typical query) instead
 * of the full ~150 MB docs file. Reads route through Symfony's app
 * cache so the parsed structures are deserialized once per worker;
 * writes invalidate the affected key(s).
 */
class IndexStore
{
    private const CACHE_KEY = 'app.search.';
    public const FILE_POSTINGS = 'postings.json';
    public const FILE_TRIGRAMS = 'trigrams.json';
    public const FILE_SOURCES = 'sources.json';
    public const SHARD_SIZE = 1000;
    public const SHARDS_DIR = 'docs';

    private string $dir;

    public function __construct(
        string $projectDir,
        private CacheInterface $cache,
    ) {
        $this->dir = $projectDir . '/resources/data/search';
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function exists(): bool
    {
        return is_dir($this->dir . '/' . self::SHARDS_DIR);
    }

    public function postings(): array { return $this->load(self::FILE_POSTINGS); }
    public function trigrams(): array { return $this->load(self::FILE_TRIGRAMS); }
    public function sources(): array  { return $this->load(self::FILE_SOURCES); }

    /**
     * Loads only the shards that contain the requested doc indexes.
     *
     * @param int[] $indexes
     * @return array<int, array> map of doc-index -> doc record (excludes nulls)
     */
    public function getDocs(array $indexes): array
    {
        if (empty($indexes)) return [];

        $byShard = [];
        foreach ($indexes as $idx) {
            $byShard[intdiv($idx, self::SHARD_SIZE)][] = $idx;
        }

        $out = [];
        foreach ($byShard as $shardId => $idxs) {
            $shard = $this->loadShard($shardId);
            foreach ($idxs as $idx) {
                $local = $idx % self::SHARD_SIZE;
                if (isset($shard[$local]) && $shard[$local] !== null) {
                    $out[$idx] = $shard[$local];
                }
            }
        }
        return $out;
    }

    public function loadShard(int $shardId): array
    {
        $key = self::CACHE_KEY . 'shard_' . $shardId;
        return $this->cache->get($key, function (ItemInterface $item) use ($shardId) {
            $item->expiresAfter(86400);
            return $this->loadShardFromDisk($shardId);
        });
    }

    /**
     * Loads every shard back into one flat docs array. Used by
     * IndexBuilder during a delta to mutate before re-writing only
     * the shards it touched.
     */
    public function loadAllShards(): array
    {
        $shardsDir = $this->dir . '/' . self::SHARDS_DIR;
        if (!is_dir($shardsDir)) return [];

        $files = glob($shardsDir . '/*.json') ?: [];
        sort($files);
        $all = [];
        foreach ($files as $f) {
            $shard = json_decode((string) @file_get_contents($f), true) ?: [];
            foreach ($shard as $doc) {
                $all[] = $doc;
            }
        }
        return $all;
    }

    public function saveShard(int $shardId, array $shard): void
    {
        $shardsDir = $this->dir . '/' . self::SHARDS_DIR;
        if (!is_dir($shardsDir)) @mkdir($shardsDir, 0755, true);

        $path = $this->shardPath($shardId);
        $tmp = $path . '.tmp';
        $encoded = json_encode($shard, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException("Could not encode shard $shardId");
        }
        if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            throw new \RuntimeException("Could not write shard $shardId");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Could not rename shard $shardId");
        }
        $this->cache->delete(self::CACHE_KEY . 'shard_' . $shardId);
    }

    /**
     * Wipes existing shard files and writes a fresh set from the given
     * dense docs array. Used by full rebuilds.
     */
    public function saveAllShards(array $docs): void
    {
        // Clean up any pre-shard docs.json from older versions of this code.
        @unlink($this->dir . '/docs.json');

        $shardsDir = $this->dir . '/' . self::SHARDS_DIR;
        if (is_dir($shardsDir)) {
            foreach (glob($shardsDir . '/*.json') ?: [] as $f) {
                @unlink($f);
            }
        } else {
            @mkdir($shardsDir, 0755, true);
        }

        $total = count($docs);
        $shardCount = (int) ceil($total / self::SHARD_SIZE);
        for ($s = 0; $s < $shardCount; $s++) {
            $shard = array_slice($docs, $s * self::SHARD_SIZE, self::SHARD_SIZE);
            $this->saveShard($s, $shard);
        }
    }

    public function save(string $file, array $data): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
        $path = $this->dir . '/' . $file;
        $tmp = $path . '.tmp';
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException("Could not encode $file");
        }
        if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            throw new \RuntimeException("Could not write $tmp");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Could not rename $tmp -> $path");
        }
        $this->cache->delete($this->cacheKey($file));
    }

    /** Drops the cached non-shard structures. Shard cache invalidation
     *  happens per-shard in saveShard(). */
    public function invalidate(): void
    {
        foreach ([self::FILE_POSTINGS, self::FILE_TRIGRAMS, self::FILE_SOURCES] as $f) {
            $this->cache->delete($this->cacheKey($f));
        }
    }

    private function shardPath(int $shardId): string
    {
        return sprintf('%s/%s/%04d.json', $this->dir, self::SHARDS_DIR, $shardId);
    }

    private function loadShardFromDisk(int $shardId): array
    {
        $path = $this->shardPath($shardId);
        if (!is_file($path)) return [];
        $raw = @file_get_contents($path);
        if ($raw === false) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function load(string $file): array
    {
        return $this->cache->get($this->cacheKey($file), function (ItemInterface $item) use ($file) {
            $item->expiresAfter(86400);
            return $this->loadFromDisk($file);
        });
    }

    private function loadFromDisk(string $file): array
    {
        $path = $this->dir . '/' . $file;
        if (!is_file($path)) return [];
        $raw = @file_get_contents($path);
        if ($raw === false) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function cacheKey(string $file): string
    {
        return self::CACHE_KEY . str_replace('.', '_', $file);
    }
}
