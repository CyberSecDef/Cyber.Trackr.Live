<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Occurrences of fixed strings in a data file, remembered per file version
 * (path + mtime + size). The home page's trust strip used to read 5.3 MB of
 * XML on every view just to print two numbers (review P15); the numbers only
 * change when the file does.
 */
final class CachedFileCount
{
    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    /** @param string[] $needles summed; 0 if the file is missing */
    public function count(string $path, array $needles): int
    {
        $path = realpath($path);
        $stat = $path === false ? false : @stat($path);
        if ($stat === false) return 0;

        $item = $this->cache->getItem('file_count_' . sha1($path . '|' . $stat['mtime'] . '|' . $stat['size'] . '|' . implode('|', $needles)));
        if ($item->isHit()) return (int) $item->get();

        $raw = @file_get_contents($path);
        if ($raw === false) return 0;
        $count = 0;
        foreach ($needles as $needle) {
            $count += substr_count($raw, $needle);
        }
        $this->cache->save($item->set($count));
        return $count;
    }
}
