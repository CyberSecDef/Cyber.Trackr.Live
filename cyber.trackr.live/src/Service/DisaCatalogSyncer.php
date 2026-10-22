<?php

namespace App\Service;

use App\Service\Search\IndexBuilder;
use ZipArchive;

/**
 * Pulls the DISA cyber.mil download catalog and mirrors fresh STIG /
 * SCAP bundles into resources/data/{stig,scap}/. Called only by the
 * app:disa:sync-{stig,scap} console commands, which the daily
 * refresh-data.sh cron runs. (The old /stig/disa_download and
 * /scap/disa_download web routes were removed: any anonymous GET
 * could start a 15-minute sync.)
 *
 * The catalog API is a Salesforce Apex endpoint that returns a flat
 * list of {DownloadLink}; we client-side filter for STIGs vs SCAPs
 * based on whether "Benchmark" appears in the link. New ZIPs land in
 * resources/data/zips/ (the source-of-truth archive); any XCCDF XML
 * inside gets extracted into resources/data/stig/ or scap/.
 *
 * Hardened relative to the original controllers:
 *   - SSL verification re-enabled (was off in the controllers).
 *   - Catalog fetch + per-file download have explicit timeouts so a
 *     hanging cyber.mil response can't stall the cron indefinitely.
 *   - Obviously-bogus headers (X-B3 trace IDs, X-SFDC-Request-Id,
 *     Google Analytics cookies) dropped — Salesforce doesn't actually
 *     require them; they were paste-artifacts from a browser session.
 *
 * Review S6 hardening:
 *   - Download links must be https on a DISA host (ALLOWED_DOWNLOADS);
 *     curl may only speak https, including on redirects, and the final
 *     URL is re-checked.
 *   - Downloads stream to a temp file with a size cap, must open as a
 *     consistent ZIP, then rename() into place. A truncated body or an
 *     HTML error page is discarded and retried next run instead of being
 *     kept forever by the is_file() skip. Extracted XML is written the
 *     same way.
 *   - Link paths are percent-encoded before fetching: DISA's catalog has
 *     a link with a literal space, which curl rejects as malformed.
 *   - One sync at a time: a flock on var/disa-sync.lock.
 */
class DisaCatalogSyncer
{
    public const KIND_STIG = 'stig';
    public const KIND_SCAP = 'scap';

    private const CATALOG_URL = 'https://www.cyber.mil/webruntime/api/apex/execute?language=en-US&asGuest=true&htmlEncode=false';
    private const CATALOG_BODY = '{"namespace":"","classname":"@udd/01pRw0000002mOj","method":"getCyberDocumentCatalogByDocumentLibrary","isContinuation":false,"params":{"documentLibrary":"STIGs"},"cacheable":false}';
    private const USER_AGENT = 'Mozilla/5.0 (cyber.trackr.live DISA mirror)';

    private const CATALOG_TIMEOUT_SECS = 60;
    private const FILE_TIMEOUT_SECS    = 180;

    /**
     * host => required path prefix. Every catalog link seen in Oct 2026 was
     * on one of these (365 dl.dod.cyber.mil, 1 dl.cyber.mil, 1 the S3
     * bucket behind dl.dod.cyber.mil).
     */
    public const ALLOWED_DOWNLOADS = [
        'dl.dod.cyber.mil'               => '/',
        'dl.cyber.mil'                   => '/',
        's3.us-gov-west-1.amazonaws.com' => '/dl.dod.cyber.mil/',
    ];

    /** Largest real bundle is ~18 MB; anything near this is not a STIG. */
    public const MAX_DOWNLOAD_BYTES = 250 * 1024 * 1024;

    private string $zipDir;
    private string $stigDir;
    private string $scapDir;
    private string $lockPath;

    public function __construct(
        string $projectDir,
        private SyncStatus $syncStatus,
        private IndexBuilder $indexBuilder,
    ) {
        $this->zipDir  = $projectDir . '/resources/data/zips';
        $this->stigDir = $projectDir . '/resources/data/stig';
        $this->scapDir = $projectDir . '/resources/data/scap';
        $this->lockPath = $projectDir . '/var/disa-sync.lock';
    }

    /**
     * Sync STIG or SCAP bundles. Pass a progress callable to receive
     * one-line status messages as work proceeds (`fn(string $line)`).
     * Returns counters describing the run.
     *
     * Setting $dryRun to true performs the catalog fetch and filter
     * step but skips all writes (downloads, file extraction, sync
     * timestamp update, search-index delta). Useful for verifying
     * the API hasn't changed without touching disk.
     *
     * @return array{candidates:int,downloaded:int,skipped:int,xml_extracted:int,errors:int}
     */
    public function sync(string $kind, ?callable $log = null, bool $dryRun = false): array
    {
        if ($kind !== self::KIND_STIG && $kind !== self::KIND_SCAP) {
            throw new \InvalidArgumentException("Unknown kind: {$kind}");
        }
        $log ??= static function (string $_l): void {};

        $lock = $dryRun ? null : $this->acquireLock();
        try {
            return $this->doSync($kind, $log, $dryRun);
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @return resource */
    private function acquireLock()
    {
        $dir = dirname($this->lockPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = fopen($this->lockPath, 'c');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open lock file {$this->lockPath}");
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new \RuntimeException('Another DISA sync is already running.');
        }
        return $fh;
    }

    /** @return array{candidates:int,downloaded:int,skipped:int,xml_extracted:int,errors:int} */
    private function doSync(string $kind, callable $log, bool $dryRun): array
    {
        $log("Fetching cyber.mil catalog …");
        $catalog = $this->fetchCatalog();
        if ($catalog === null || !isset($catalog->returnValue)) {
            throw new \RuntimeException('Catalog response missing returnValue — DISA may have changed the API.');
        }
        $entries = $catalog->returnValue;
        $log(sprintf('Catalog returned %d entries.', count($entries)));

        $destDir = $kind === self::KIND_STIG ? $this->stigDir : $this->scapDir;
        if (!is_dir($this->zipDir) || !is_dir($destDir)) {
            throw new \RuntimeException("Target directories missing: {$this->zipDir} / {$destDir}");
        }

        $stats = [
            'candidates'    => 0,
            'downloaded'    => 0,
            'skipped'       => 0,
            'xml_extracted' => 0,
            'errors'        => 0,
        ];

        foreach ($entries as $item) {
            $link = (string)($item->DownloadLink ?? '');
            if (!$this->isCandidateLink($link, $kind)) continue;
            $stats['candidates']++;

            if (!self::isAllowedDownloadUrl($link)) {
                $stats['errors']++;
                $log(sprintf('  ! not an allowed DISA download URL, skipping: %s', $link));
                continue;
            }

            $zipName = self::localZipName($link);
            $zipPath = $this->zipDir . '/' . $zipName;

            if (is_file($zipPath)) {
                $stats['skipped']++;
                continue;
            }

            if ($dryRun) {
                $log(sprintf('  [dry-run] would download %s', $zipName));
                $stats['downloaded']++;
                continue;
            }

            $log(sprintf('  ↓ %s', $zipName));
            $tmpPath = $this->zipDir . '/.' . $zipName . '.' . bin2hex(random_bytes(4)) . '.part';
            $error = $this->fetchToFile($link, $tmpPath);
            if ($error !== null) {
                @unlink($tmpPath);
                $stats['errors']++;
                $log(sprintf('    failed to download %s (%s) — skipping', $zipName, $error));
                continue;
            }
            if (!self::installZip($tmpPath, $zipPath)) {
                $stats['errors']++;
                $log(sprintf('    %s is not a valid ZIP — discarded, will retry next run', $zipName));
                continue;
            }
            $stats['downloaded']++;

            // Extract XCCDF XMLs into the per-kind dir.
            $extracted = $this->extractXmls($zipPath, $destDir, $log);
            $stats['xml_extracted'] += $extracted;
        }

        if (!$dryRun) {
            $this->syncStatus->markDisaSyncedNow();
            $this->indexBuilder->syncDeltas();
        }

        return $stats;
    }

    /**
     * Filter rule from the original controllers, preserved verbatim:
     *   - must live under wp-content
     *   - must begin with "U_" and end ".zip"
     *   - exclude STIGViewer / STIG_Library archives
     *   - STIG kind: "stigs" in path AND no "Benchmark" in name
     *   - SCAP kind: "Benchmark" in name (regardless of "stigs")
     */
    private function isCandidateLink(string $link, string $kind): bool
    {
        if ($link === '')                              return false;
        if (!stristr($link, 'wp-content'))             return false;
        if (!stristr($link, 'U_'))                     return false;
        if (!stristr($link, '.zip'))                   return false;
        if (stristr($link, 'STIGViewer'))              return false;
        if (stristr($link, 'STIG_Library'))            return false;

        if ($kind === self::KIND_STIG) {
            if (stristr($link, 'Benchmark'))           return false;
            if (!stristr($link, 'stigs'))              return false;
        } else { // SCAP
            if (!stristr($link, 'Benchmark'))          return false;
        }
        return true;
    }

    private function fetchCatalog(): ?object
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::CATALOG_URL,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => self::CATALOG_BODY,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_ENCODING       => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => self::CATALOG_TIMEOUT_SECS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'Accept: */*',
                'Accept-Encoding: gzip, deflate, br',
                'Accept-Language: en-US,en;q=0.9',
                'Content-Type: application/json; charset=utf-8',
                'Origin: https://www.cyber.mil',
                'Referer: https://www.cyber.mil/stigs/downloads',
            ],
            CURLOPT_COOKIE         => http_build_query([
                // Required to bypass the cookie banner that otherwise
                // wraps the response in a consent gate.
                'CookieConsentPolicy'         => '0:1',
                'LSKey-c$CookieConsentPolicy' => '0:1',
            ]),
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException("Catalog fetch failed: {$err}");
        }
        return json_decode($body) ?: null;
    }

    /**
     * https on an allowlisted DISA host and path, no credentials, default
     * port. Exact host match, so dl.dod.cyber.mil.example.com fails.
     */
    public static function isAllowedDownloadUrl(string $url): bool
    {
        $p = parse_url($url);
        if ($p === false || strtolower($p['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($p['user']) || isset($p['pass']) || (isset($p['port']) && $p['port'] !== 443)) {
            return false;
        }
        $prefix = self::ALLOWED_DOWNLOADS[strtolower($p['host'] ?? '')] ?? null;
        return $prefix !== null && str_starts_with($p['path'] ?? '', $prefix);
    }

    /**
     * Percent-encode each path segment ("for Linux" → "for%20Linux"),
     * decoding first so an already-encoded link isn't double-encoded.
     */
    public static function encodeUrl(string $url): string
    {
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host'])) {
            return $url;
        }
        $path = implode('/', array_map(
            static fn (string $seg) => rawurlencode(rawurldecode($seg)),
            explode('/', $p['path'] ?? '')
        ));
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '')
            . $path . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    /** Local archive name: the decoded last path segment, never a path. */
    public static function localZipName(string $url): string
    {
        return basename(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
    }

    /**
     * Move a downloaded temp file into place only if it's a consistent ZIP
     * with at least one entry; otherwise delete it. rename() within one
     * directory is atomic, so readers never see a partial archive.
     */
    public static function installZip(string $tmpPath, string $finalPath): bool
    {
        $zip = new ZipArchive();
        // An empty body would open as a new, empty archive (deprecated in PHP 8.5).
        $ok = (int) @filesize($tmpPath) > 0 && $zip->open($tmpPath, ZipArchive::CHECKCONS) === true;
        if ($ok) {
            $ok = $zip->numFiles > 0;
            $zip->close();
        }
        if (!$ok || !rename($tmpPath, $finalPath)) {
            @unlink($tmpPath);
            return false;
        }
        return true;
    }

    /** @return string|null null on success, else a short reason */
    private function fetchToFile(string $url, string $tmpPath): ?string
    {
        $fh = fopen($tmpPath, 'xb');
        if ($fh === false) {
            return 'cannot create temp file';
        }
        $https = defined('CURLOPT_PROTOCOLS_STR')
            ? [CURLOPT_PROTOCOLS_STR => 'https', CURLOPT_REDIR_PROTOCOLS_STR => 'https']
            : [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS];

        $ch = curl_init();
        curl_setopt_array($ch, $https + [
            CURLOPT_URL            => self::encodeUrl($url),
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => self::FILE_TIMEOUT_SECS,
            CURLOPT_CONNECTTIMEOUT => 15,
            // MAXFILESIZE alone only checks Content-Length on older libcurl
            // (prod has 7.81), so also abort mid-transfer.
            CURLOPT_MAXFILESIZE    => self::MAX_DOWNLOAD_BYTES,
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_XFERINFOFUNCTION => static fn ($_ch, $_dlTotal, int $dlNow) => $dlNow > self::MAX_DOWNLOAD_BYTES ? 1 : 0,
        ]);
        $ok    = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $err   = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false) {
            return $err !== '' ? $err : 'transfer failed';
        }
        if ($code !== 200) {
            return "HTTP {$code}";
        }
        if (!self::isAllowedDownloadUrl($final)) {
            return "redirected off the allowlist to {$final}";
        }
        return null;
    }

    private function extractXmls(string $zipPath, string $destDir, callable $log): int
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            $log(sprintf('    could not open %s', basename($zipPath)));
            return 0;
        }
        $n = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = basename((string)$zip->getNameIndex($i));
            if (!str_starts_with($name, 'U_'))                          continue;
            if (!stristr($name, '.xml'))                                continue;
            $target = $destDir . '/' . $name;
            if (is_file($target))                                       continue;
            $bytes = $zip->getFromIndex($i);
            if ($bytes === false)                                       continue;
            // Temp file + rename so a reader (or a crash) never sees a
            // half-written XML in the data tree.
            $tmp = $destDir . '/.' . $name . '.' . bin2hex(random_bytes(4)) . '.part';
            if (file_put_contents($tmp, $bytes) === false || !rename($tmp, $target)) {
                @unlink($tmp);
                continue;
            }
            $log(sprintf('    + %s', $name));
            $n++;
        }
        $zip->close();
        return $n;
    }
}
