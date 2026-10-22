<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

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
 *     is generated at most once; callers pass the toc's canonical version and
 *     release so "2" and "02" share an entry. The pool is cache.mitigation,
 *     which deploys don't clear;
 *   - symfony/rate-limiter caps cache-miss generations per client IP and
 *     site-wide, bounding Gemini spend and process spawning. (The old
 *     hand-rolled counter only set its expiry on the first save, so after a
 *     second request it never expired: review S8.) A limited call throws
 *     MitigationRateLimitedException so the UI can say "try later";
 *   - the process has a hard timeout so a hung helper can't pin a PHP worker;
 *   - the whole feature is a no-op unless MITIGATION_SCRIPT points at a real
 *     file, so dev / test / CI never shell out.
 */
class MitigationGenerator
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly RateLimiterFactoryInterface $mitigationLimiter,
        private readonly RateLimiterFactoryInterface $mitigationGlobalLimiter,
        private readonly string $scriptPath = '',
        private readonly string $pythonBin = '/usr/bin/python3',
        private readonly int $timeoutSeconds = 25,
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
     * the input is invalid, or the helper failed.
     *
     * @throws MitigationRateLimitedException when a cache miss is over a limit
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
        $this->consumeOrThrow($this->mitigationLimiter, $clientIp);
        $this->consumeOrThrow($this->mitigationGlobalLimiter, 'global');

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

    private function consumeOrThrow(RateLimiterFactoryInterface $factory, string $key): void
    {
        $limit = $factory->create($key)->consume(1);
        if (!$limit->isAccepted()) {
            throw new MitigationRateLimitedException(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }
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
