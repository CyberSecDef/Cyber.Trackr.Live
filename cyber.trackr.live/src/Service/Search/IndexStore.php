<?php

namespace App\Service\Search;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Loads and saves the four index files under resources/data/search/.
 *
 *   docs.json      - numerically-indexed array of docs.
 *                    [{id, type, title, snippet, text, ...display fields}, ...]
 *   postings.json  - {token: [doc_idx, doc_idx, ...]} sorted, deduped.
 *   trigrams.json  - {trigram: [token, ...]} for fuzzy fallback.
 *   sources.json   - {source_path: {sha256, mtime, doc_indexes: [...]}}
 *                    used by IndexBuilder for delta detection.
 *
 * Reads are routed through Symfony's app cache (CacheInterface) so the
 * parsed structures are deserialized once per worker rather than per
 * request. Writes invalidate the cache key atomically.
 */
class IndexStore
{
    private const CACHE_KEY = 'app.search.';
    public const FILE_DOCS = 'docs.json';
    public const FILE_POSTINGS = 'postings.json';
    public const FILE_TRIGRAMS = 'trigrams.json';
    public const FILE_SOURCES = 'sources.json';

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
        return is_file($this->dir . '/' . self::FILE_DOCS);
    }

    public function docs(): array       { return $this->load(self::FILE_DOCS); }
    public function postings(): array   { return $this->load(self::FILE_POSTINGS); }
    public function trigrams(): array   { return $this->load(self::FILE_TRIGRAMS); }
    public function sources(): array    { return $this->load(self::FILE_SOURCES); }

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

    /** Drop every cached structure, e.g. after a full rebuild. */
    public function invalidate(): void
    {
        foreach ([self::FILE_DOCS, self::FILE_POSTINGS, self::FILE_TRIGRAMS, self::FILE_SOURCES] as $f) {
            $this->cache->delete($this->cacheKey($f));
        }
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
