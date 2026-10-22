<?php

namespace App\Tests\Service;

use App\Service\DisaCatalogSyncer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review S6: download links are allowlisted, percent-encoded, and only
 * installed when they're a valid ZIP.
 */
class DisaCatalogSyncerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/disasync_' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        rmdir($this->dir);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function urls(): iterable
    {
        yield 'dl.dod.cyber.mil'          => ['https://dl.dod.cyber.mil/wp-content/uploads/stigs/zip/U_Active_Directory_Forest_V3R2_STIG.zip', true];
        yield 'dl.cyber.mil'              => ['https://dl.cyber.mil/wp-content/uploads/stigs/zip/u_draft_cci_specification_v2r0.2.zip', true];
        yield 'DISA S3 bucket'            => ['https://s3.us-gov-west-1.amazonaws.com/dl.dod.cyber.mil/wp-content/uploads/stigs/zip/U_Apple_iOS-iPadOS_17_V2R2_STIG.zip', true];
        yield 'host is case-insensitive'  => ['https://DL.DOD.CYBER.MIL/wp-content/uploads/stigs/zip/U_X_STIG.zip', true];
        yield 'explicit 443'              => ['https://dl.dod.cyber.mil:443/wp-content/uploads/stigs/zip/U_X_STIG.zip', true];
        yield 'plain http'                => ['http://dl.dod.cyber.mil/wp-content/uploads/stigs/zip/U_X_STIG.zip', false];
        yield 'other scheme'              => ['ftp://dl.dod.cyber.mil/wp-content/uploads/stigs/zip/U_X_STIG.zip', false];
        yield 'lookalike suffix'          => ['https://dl.dod.cyber.mil.evil.example/wp-content/uploads/stigs/zip/U_X_STIG.zip', false];
        yield 'lookalike prefix'          => ['https://evil-dl.dod.cyber.mil/wp-content/uploads/stigs/zip/U_X_STIG.zip', false];
        yield 'other host'                => ['https://example.com/wp-content/uploads/stigs/zip/U_X_STIG.zip', false];
        yield 'internal address'          => ['https://169.254.169.254/wp-content/U_X.zip', false];
        yield 'other S3 bucket'           => ['https://s3.us-gov-west-1.amazonaws.com/attacker-bucket/wp-content/U_X_STIG.zip', false];
        yield 'credentials in URL'        => ['https://user:pw@dl.dod.cyber.mil/wp-content/uploads/stigs/zip/U_X_STIG.zip', false];
        yield 'non-default port'          => ['https://dl.dod.cyber.mil:8443/wp-content/uploads/stigs/zip/U_X_STIG.zip', false];
        yield 'not a URL'                 => ['U_X_STIG.zip', false];
    }

    #[DataProvider('urls')]
    public function testDownloadUrlAllowlist(string $url, bool $allowed): void
    {
        $this->assertSame($allowed, DisaCatalogSyncer::isAllowedDownloadUrl($url));
    }

    public function testUrlWithASpaceIsEncodedAndNamedByItsDecodedPath(): void
    {
        // The real catalog link that failed every night with "Malformed input to a URL function".
        $link = 'https://dl.dod.cyber.mil/wp-content/uploads/stigs/zip/U_Splunk_Enterprise_8-x_for Linux_V2R3_STIG.zip';

        $this->assertSame(
            'https://dl.dod.cyber.mil/wp-content/uploads/stigs/zip/U_Splunk_Enterprise_8-x_for%20Linux_V2R3_STIG.zip',
            DisaCatalogSyncer::encodeUrl($link)
        );
        $this->assertSame('U_Splunk_Enterprise_8-x_for Linux_V2R3_STIG.zip', DisaCatalogSyncer::localZipName($link));
    }

    public function testEncodingIsIdempotentAndLeavesNormalLinksAlone(): void
    {
        $plain = 'https://dl.dod.cyber.mil/wp-content/uploads/stigs/zip/U_Active_Directory_Forest_V3R2_STIG.zip';
        $this->assertSame($plain, DisaCatalogSyncer::encodeUrl($plain));

        $encoded = 'https://dl.dod.cyber.mil/wp-content/uploads/stigs/zip/U_A_for%20B_STIG.zip';
        $this->assertSame($encoded, DisaCatalogSyncer::encodeUrl($encoded), 'no double-encoding');
        $this->assertSame('U_A_for B_STIG.zip', DisaCatalogSyncer::localZipName($encoded));
    }

    public function testLocalNameIsNeverAPath(): void
    {
        $this->assertSame('U_X.zip', DisaCatalogSyncer::localZipName('https://dl.dod.cyber.mil/wp-content/a/..%2F..%2FU_X.zip'));
    }

    private function validZip(): string
    {
        $path = $this->dir . '/.valid.part';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('U_Test_STIG_V1R1_Manual-xccdf.xml', str_repeat('<Benchmark/>', 2000));
        $zip->close();
        return $path;
    }

    public function testValidZipIsMovedIntoPlace(): void
    {
        $tmp = $this->validZip();
        $final = $this->dir . '/U_Test_STIG.zip';

        $this->assertTrue(DisaCatalogSyncer::installZip($tmp, $final));
        $this->assertFileExists($final);
        $this->assertFileDoesNotExist($tmp);
    }

    /** @return iterable<string, array{callable(string): string}> */
    public static function badBodies(): iterable
    {
        yield 'HTML error page' => [fn (string $valid) => '<!DOCTYPE html><html><body>Access Denied</body></html>'];
        yield 'truncated ZIP'   => [fn (string $valid) => substr($valid, 0, (int) (strlen($valid) / 2))];
        yield 'empty body'      => [fn (string $valid) => ''];
    }

    #[DataProvider('badBodies')]
    public function testInvalidDownloadIsDiscardedAndNothingIsInstalled(callable $body): void
    {
        $tmp = $this->validZip();
        file_put_contents($tmp, $body(file_get_contents($tmp)));
        $final = $this->dir . '/U_Test_STIG.zip';

        $this->assertFalse(DisaCatalogSyncer::installZip($tmp, $final));
        $this->assertFileDoesNotExist($final, 'a bad download must not land where is_file() would skip it forever');
        $this->assertFileDoesNotExist($tmp, 'temp file is cleaned up');
    }
}
