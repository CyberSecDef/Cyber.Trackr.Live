<?php

namespace App\Service;

/**
 * Locates the air-gap Docker image bundle (a `docker save | gzip` tarball)
 * that bin/build-image.sh drops in dist/. When one is present the site offers
 * a download + run-instructions page; when it isn't (e.g. inside the container
 * itself, where *.tar.gz is .dockerignore'd) the footer link stays hidden.
 *
 * Wired to Twig as the global `docker_image` so the footer can conditionally
 * render the link without every controller having to pass it through.
 */
class DockerImageLocator
{
    private string $distDir;
    private string $composePath;
    private bool $resolved = false;

    /** @var array{filename:string, path:string, bytes:int, size:string, tag:string, image:string}|null */
    private ?array $latest = null;

    public function __construct(string $projectDir)
    {
        $this->distDir = $projectDir . '/dist';
        $this->composePath = $projectDir . '/docker-compose.yml';
    }

    /**
     * Newest *.tar.gz bundle in dist/, or null when none exists.
     *
     * @return array{filename:string, path:string, bytes:int, size:string, tag:string, image:string}|null
     */
    public function latest(): ?array
    {
        if ($this->resolved) {
            return $this->latest;
        }
        $this->resolved = true;

        if (!is_dir($this->distDir)) {
            return null;
        }
        $files = glob($this->distDir . '/*.tar.gz') ?: [];
        if ($files === []) {
            return null;
        }

        // Newest by mtime so a fresh build supersedes an older bundle.
        usort($files, fn(string $a, string $b): int => (int) @filemtime($b) <=> (int) @filemtime($a));
        $path = $files[0];
        $filename = basename($path);
        $bytes = (int) (@filesize($path) ?: 0);
        $tag = $this->tagFromFilename($filename);

        return $this->latest = [
            'filename' => $filename,
            'path'     => $path,
            'bytes'    => $bytes,
            'size'     => $this->humanSize($bytes),
            'tag'      => $tag ?? 'latest',
            'image'    => 'cyber-trackr:' . ($tag ?? 'latest'),
        ];
    }

    /** Raw docker-compose.yml so the instructions page can show it verbatim. */
    public function composeYaml(): string
    {
        if (!is_file($this->composePath)) {
            return '';
        }
        return (string) @file_get_contents($this->composePath);
    }

    /** "cyber-trackr-2026-06.tar.gz" -> "2026-06"; null if it doesn't match. */
    private function tagFromFilename(string $filename): ?string
    {
        return preg_match('/^cyber-trackr-(.+)\.tar\.gz$/', $filename, $m) ? $m[1] : null;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $n = (float) max(0, $bytes);
        $i = 0;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return sprintf($i === 0 ? '%d %s' : '%.1f %s', $n, $units[$i]);
    }
}
