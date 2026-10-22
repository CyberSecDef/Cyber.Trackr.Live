<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A cheap fingerprint of everything a page or API response is built from,
 * so HTTP caching can answer "has this changed?" before doing the work
 * (review P10/P11). No database and no stamp file to forget to bump:
 *
 *  - code + templates: the compiled container's mtime. bin/deploy.sh runs
 *    cache:clear, which rebuilds it; prod Twig never recompiles otherwise.
 *  - data: resources/data and each of its direct children. Every writer
 *    (AtomicFile, SyncStatus, DisaCatalogSyncer, the search index pointer)
 *    renames a new file into place, which changes the file's inode and the
 *    parent directory's mtime, so a refresh always moves this.
 *
 * About 25 stat() calls; computed once per request.
 */
final class DataVersion
{
    private ?string $data = null;

    public function __construct(
        private readonly string $projectDir,
        #[Autowire('%kernel.cache_dir%')] private readonly string $cacheDir,
        #[Autowire('%kernel.container_class%')] private readonly string $containerClass,
    ) {
    }

    /** Changes whenever the code or any data file changes. */
    public function data(): string
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $container = $this->cacheDir . '/' . $this->containerClass . '.php';
        $parts = ['code:' . (@filemtime($container) ?: @filemtime($this->cacheDir))];

        $dir = $this->projectDir . '/resources/data';
        $parts[] = 'data:' . @filemtime($dir);
        foreach (@scandir($dir) ?: [] as $name) {
            if ($name[0] === '.') {
                continue; // ., .., and in-flight AtomicFile temp files
            }
            $st = @stat($dir . '/' . $name);
            if ($st !== false) {
                $parts[] = $name . ':' . $st['ino'] . ':' . $st['mtime'] . ':' . $st['size'];
            }
        }

        return $this->data = sha1(implode("\n", $parts));
    }

    /**
     * Pages also depend on today's date ("No. 10 · 2026" on the home page,
     * "3 days ago" freshness labels), so the version rolls over at midnight.
     */
    public function page(): string
    {
        return sha1($this->data() . "\n" . date('Y-m-d'));
    }
}
