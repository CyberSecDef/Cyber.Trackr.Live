<?php

namespace App\Service;

use App\Service\Search\IndexBuilder;
use ZipArchive;

/**
 * Pulls the DISA cyber.mil download catalog and mirrors fresh STIG /
 * SCAP bundles into resources/data/{stig,scap}/. Replaces the
 * /stig/disa_download and /scap/disa_download controller actions'
 * hand-rolled scrapers with a single reusable engine that the
 * controllers AND the daily refresh-data.sh cron both call.
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

    private string $zipDir;
    private string $stigDir;
    private string $scapDir;

    public function __construct(
        string $projectDir,
        private SyncStatus $syncStatus,
        private IndexBuilder $indexBuilder,
    ) {
        $this->zipDir  = $projectDir . '/resources/data/zips';
        $this->stigDir = $projectDir . '/resources/data/stig';
        $this->scapDir = $projectDir . '/resources/data/scap';
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

            $zipName = basename($link);
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
            $bytes = $this->fetchUrl($link);
            if ($bytes === null) {
                $stats['errors']++;
                $log(sprintf('    failed to download %s — skipping', $zipName));
                continue;
            }
            if (file_put_contents($zipPath, $bytes, LOCK_EX) === false) {
                $stats['errors']++;
                $log(sprintf('    failed to write %s — skipping', $zipName));
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

    private function fetchUrl(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => self::FILE_TIMEOUT_SECS,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) {
            return null;
        }
        return $body;
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
            if (file_put_contents($target, $bytes, LOCK_EX) === false)  continue;
            $log(sprintf('    + %s', $name));
            $n++;
        }
        $zip->close();
        return $n;
    }
}
