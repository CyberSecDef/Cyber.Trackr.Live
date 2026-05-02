<?php
namespace App\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Push URL-change notifications to the IndexNow protocol so participating
 * search engines (Bing, DuckDuckGo via Bing, Yandex, Seznam, Naver) re-fetch
 * just-changed pages within minutes instead of waiting on the natural
 * crawl cycle.
 *
 * The IndexNow key + the public URL of the key file are derived from the
 * one .txt file under /public/ named like {key}.txt. We auto-discover it
 * so adding/rotating the key is just dropping a new file — no env-var
 * changes needed.
 *
 * Usage:
 *
 *     // single URL (relative or absolute)
 *     $pinger->ping(['/stig/Amazon_Linux_2023/1/3']);
 *
 *     // up to 10,000 URLs per call (the IndexNow protocol's batch limit)
 *     $pinger->ping($urlsArray);
 *
 * Failures are logged and swallowed — IndexNow is best-effort SEO
 * acceleration, not core functionality.
 */
class IndexNowPinger
{
    private const ENDPOINT = 'https://api.indexnow.org/IndexNow';

    /** Hard-cap so we don't violate the protocol's 10,000-URL batch limit. */
    private const BATCH_LIMIT = 10000;

    public function __construct(
        private string $projectDir,
        private string $publicHost = 'cyber.trackr.live',
        private string $publicScheme = 'https',
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Discover the IndexNow key by scanning /public/ for the first
     * {32-128-hex-chars}.txt file whose contents match the filename stem.
     * Returns null if no valid key file is present so the caller can
     * skip the ping cleanly.
     */
    public function discoverKey(): ?array
    {
        $publicDir = $this->projectDir . '/public';
        if (!is_dir($publicDir)) return null;

        foreach (glob($publicDir . '/*.txt') as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (!preg_match('/^[a-f0-9]{32,128}$/i', $name)) continue;
            $body = trim((string) @file_get_contents($path));
            if (strcasecmp($body, $name) !== 0) continue;
            return [
                'key'         => $name,
                'keyLocation' => $this->publicScheme . '://' . $this->publicHost . '/' . basename($path),
            ];
        }
        return null;
    }

    /**
     * Submit a list of URLs to IndexNow. Relative paths are resolved against
     * the public host. Returns true on protocol-level success (HTTP 200/202),
     * false on any failure (no key, transport error, non-2xx response).
     *
     * @param array<int,string> $urls
     */
    public function ping(array $urls): bool
    {
        $key = $this->discoverKey();
        if ($key === null) {
            $this->logger->info('IndexNow ping skipped: no key file under /public/.');
            return false;
        }
        if ($urls === []) return false;

        $absolute = $this->normalizeUrls($urls);
        if ($absolute === []) return false;

        if (count($absolute) > self::BATCH_LIMIT) {
            $absolute = array_slice($absolute, 0, self::BATCH_LIMIT);
        }

        $payload = [
            'host'        => $this->publicHost,
            'key'         => $key['key'],
            'keyLocation' => $key['keyLocation'],
            'urlList'     => array_values($absolute),
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            $this->logger->warning('IndexNow ping failed: payload encode error.');
            return false;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json; charset=utf-8\r\nUser-Agent: Cyber-Trackr/IndexNow\r\n",
                'content'       => $body,
                'timeout'       => 8,
                'ignore_errors' => true,
            ],
            'https' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json; charset=utf-8\r\nUser-Agent: Cyber-Trackr/IndexNow\r\n",
                'content'       => $body,
                'timeout'       => 8,
                'ignore_errors' => true,
            ],
        ]);

        $start = microtime(true);
        $response = @file_get_contents(self::ENDPOINT, false, $ctx);
        $elapsedMs = (int) ((microtime(true) - $start) * 1000);

        $status = $this->extractHttpStatus($http_response_header ?? []);
        $ok = $status >= 200 && $status < 300;

        if ($ok) {
            $this->logger->info(sprintf(
                'IndexNow ping ok: %d urls, HTTP %d, %dms.',
                count($absolute), $status, $elapsedMs
            ));
        } else {
            $this->logger->warning(sprintf(
                'IndexNow ping failed: %d urls, HTTP %d, %dms. Response: %s',
                count($absolute), $status, $elapsedMs,
                substr((string) $response, 0, 200)
            ));
        }

        return $ok;
    }

    /**
     * Convert relative paths to absolute URLs against the configured public
     * host; pass-through anything already absolute on the same host.
     * Drops anything that points elsewhere — IndexNow rejects mixed-host
     * payloads with the entire batch failing.
     *
     * @param array<int,string> $urls
     * @return array<int,string>
     */
    private function normalizeUrls(array $urls): array
    {
        $out = [];
        $seen = [];
        $hostPrefix = $this->publicScheme . '://' . $this->publicHost;
        foreach ($urls as $u) {
            $u = trim((string) $u);
            if ($u === '') continue;
            if (str_starts_with($u, '/')) {
                $u = $hostPrefix . $u;
            }
            // Reject anything not on our host — IndexNow batches are
            // single-host so a stray external URL would fail the whole call.
            if (!str_starts_with($u, $hostPrefix . '/') && $u !== $hostPrefix) {
                continue;
            }
            if (isset($seen[$u])) continue;
            $seen[$u] = true;
            $out[] = $u;
        }
        return $out;
    }

    /**
     * Pull the HTTP status code out of $http_response_header. The first
     * line looks like "HTTP/1.1 202 Accepted".
     *
     * @param array<int,string> $headers
     */
    private function extractHttpStatus(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/[\d.]+\s+(\d{3})#', $h, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }
}
