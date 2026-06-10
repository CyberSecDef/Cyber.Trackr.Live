<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Process\Process;

/**
 * Generates an AI "mitigation statement" for a STIG vulnerability by invoking a
 * local Python helper that calls Google Gemini.
 *
 * This is the hardened replacement for the original inline shell_exec() in
 * ApiController, which interpolated request data straight into a /bin/sh command
 * (remote code execution) behind a public-derivable sha1 "key" (no real gate).
 * Protections here, for the "public on-demand but bounded" model:
 *
 *   - invocation uses an argument vector (no shell), so request data can never
 *     be re-parsed as shell syntax — closes the command injection;
 *   - every value that becomes an argument is validated against a strict
 *     allowlist and may not look like a CLI flag — closes argument injection;
 *   - results are cached per (title, version, release, id) so each vulnerability
 *     is generated at most once;
 *   - a per-client fixed-window limiter caps how often generation can be
 *     triggered, bounding Gemini spend and process spawning;
 *   - the process has a hard timeout so a hung helper can't pin a PHP worker;
 *   - the whole feature is a no-op unless MITIGATION_SCRIPT points at a real
 *     file, so dev / test / CI never shell out.
 */
class MitigationGenerator
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly string $scriptPath = '',
        private readonly string $pythonBin = '/usr/bin/python3',
        private readonly int $timeoutSeconds = 25,
        private readonly int $rateLimitPerHour = 30,
        private readonly int $cacheTtlSeconds = 2592000, // 30 days; STIG vulns are static
    ) {
    }

    /** The feature is active only when a real helper script is configured. */
    public function isEnabled(): bool
    {
        return $this->scriptPath !== '' && is_file($this->scriptPath);
    }

    /**
     * Cached-or-generated mitigation text, or null when the feature is disabled,
     * the input is invalid, the caller is rate-limited, or the helper failed.
     */
    public function generate(string $title, string $version, string $release, string $id, string $clientIp): ?string
    {
        if (!$this->isEnabled() || !$this->validArgs($title, $version, $release, $id)) {
            return null;
        }

        $cacheKey = 'mitigation.' . hash('sha256', implode("\0", [$title, $version, $release, $id]));
        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            return $item->get();
        }

        // Only spend a rate-limit token on real work (a cache miss). Hits are free.
        if (!$this->allowRequest($clientIp)) {
            return null;
        }

        $output = $this->runProcess([$this->pythonBin, $this->scriptPath, $title, $version, $release, $id]);
        if ($output === null || $output === '') {
            return null;
        }

        $item->set($output)->expiresAfter($this->cacheTtlSeconds);
        $this->cache->save($item);

        return $output;
    }

    /**
     * Strict allowlist for every value that becomes a process argument. The
     * leading-dash rejection stops a value from being read as a CLI option even
     * though we never go through a shell.
     */
    private function validArgs(string $title, string $version, string $release, string $id): bool
    {
        foreach ([$title, $version, $release, $id] as $arg) {
            if ($arg === '' || $arg[0] === '-') {
                return false;
            }
        }

        return preg_match('/^[A-Za-z0-9 _.()\/-]{1,120}$/', $title) === 1
            && preg_match('/^[0-9]{1,4}$/', $version) === 1
            && preg_match('/^[0-9]{1,4}$/', $release) === 1
            && preg_match('/^V-[0-9]{1,9}$/', $id) === 1;
    }

    /**
     * Fixed-window per-client limiter backed by the shared cache pool. Counts
     * the request and returns false once the window's budget is spent.
     */
    private function allowRequest(string $clientIp): bool
    {
        $key = 'mitigation.rl.' . hash('sha256', $clientIp);
        $item = $this->cache->getItem($key);
        $isNewWindow = !$item->isHit();
        $count = $isNewWindow ? 0 : (int) $item->get();
        if ($count >= $this->rateLimitPerHour) {
            return false;
        }

        $item->set($count + 1);
        if ($isNewWindow) {
            $item->expiresAfter(3600);
        }
        $this->cache->save($item);

        return true;
    }

    /**
     * Run the helper as an argument vector (no shell) with a hard timeout.
     * Returns trimmed stdout, or null on any failure. Isolated as a seam so
     * tests can stub it without spawning a real process.
     *
     * @param array<int,string> $cmd
     */
    protected function runProcess(array $cmd): ?string
    {
        $process = new Process($cmd);
        $process->setTimeout($this->timeoutSeconds);
        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }
}
