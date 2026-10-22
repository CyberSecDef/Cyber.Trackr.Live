<?php

namespace App\Tests\Service;

use App\Service\ScapTocBuilder;
use PHPUnit\Framework\TestCase;

/**
 * SCAP toc rows come out newest first with same-day releases in release
 * order, the same on every host (the toc used to follow directory order and
 * the version list reversed an ascending date sort).
 */
class ScapTocBuilderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scaptoc_' . uniqid('', true);
        mkdir($this->dir . '/resources/data/scap', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function xml(string $file, string $title, string $version, string $date): void
    {
        file_put_contents("{$this->dir}/resources/data/scap/$file",
            '<?xml version="1.0"?><Benchmark xmlns="http://checklists.nist.gov/xccdf/1.2">'
            . "<status date=\"$date\">accepted</status><title>$title</title><version>$version</version></Benchmark>");
    }

    private function order(string $title): array
    {
        $rows = (new ScapTocBuilder($this->dir))->rebuildAll()[$title];
        return array_map(fn($r) => $r['version'] . '.' . $r['release'] . ' ' . $r['filename'], $rows);
    }

    public function testSameDayReleasesAreNewestFirst(): void
    {
        $this->xml('U_Win10_V3R4_STIG_SCAP_1-2_Benchmark.xml', 'Win10', '3.4', '2025-02-25');
        $this->xml('U_Win10_V3R10_STIG_SCAP_1-2_Benchmark.xml', 'Win10', '3.10', '2025-02-25');
        $this->xml('U_Win10_V3R5_STIG_SCAP_1-2_Benchmark.xml', 'Win10', '3.5', '2025-02-25');
        $this->xml('U_Win10_V3R6_STIG_SCAP_1-2_Benchmark.xml', 'Win10', '3.6', '2025-05-01');

        $this->assertSame(['3.6', '3.10', '3.5', '3.4'], array_map(fn($r) => strtok($r, ' '), $this->order('Win10')));
    }

    /** Firefox Linux and Windows share a title and release; the page shows the last row (Linux, as on prod). */
    public function testPlatformVariantsOfOneReleaseKeepLinuxLast(): void
    {
        $this->xml('U_MOZ_Firefox_Linux_V6R3_STIG_SCAP_1-2_Benchmark.xml', 'Firefox', '6.3', '2022-09-09');
        $this->xml('U_MOZ_Firefox_Windows_V6R3_STIG_SCAP_1-2_Benchmark.xml', 'Firefox', '6.3', '2022-09-09');

        $this->assertSame([
            '6.3 U_MOZ_Firefox_Windows_V6R3_STIG_SCAP_1-2_Benchmark.xml',
            '6.3 U_MOZ_Firefox_Linux_V6R3_STIG_SCAP_1-2_Benchmark.xml',
        ], $this->order('Firefox'));
    }
}
