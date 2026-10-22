<?php

namespace App\Tests\Service;

use App\Service\ScapTocBuilder;
use PHPUnit\Framework\TestCase;

/**
 * SCAP toc rows come out newest first with same-day releases in release
 * order, the same on every host (the toc used to follow directory order and
 * the version list reversed an ascending date sort), and Firefox's platforms
 * each get their own title.
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

    /**
     * DISA titled Firefox's Linux/RHEL and Windows SCAP benchmarks the same
     * until V6R4 (and V6R3 Windows "for Linux"); each platform gets its own.
     */
    public function testFirefoxIsSplitByPlatform(): void
    {
        $this->xml('U_Mozilla_Firefox_RHEL_V1R5_STIG_SCAP_1-2_Benchmark.xml', 'Mozilla Firefox', '1.5', '2020-01-24');
        $this->xml('U_Mozilla_Firefox_Windows_V1R5_STIG_SCAP_1-2_Benchmark.xml', 'Mozilla Firefox', '1.5', '2020-01-24');
        $this->xml('U_MOZ_Firefox_Linux_V6R3_STIG_SCAP_1-2_Benchmark.xml', 'Mozilla Firefox for Linux STIG SCAP Benchmark', '6.3', '2022-09-09');
        $this->xml('U_MOZ_Firefox_Windows_V6R3_STIG_SCAP_1-2_Benchmark.xml', 'Mozilla Firefox for Linux STIG SCAP Benchmark', '6.3', '2022-09-09');
        $this->xml('U_MOZ_Firefox_Windows_V6R4_STIG_SCAP_1-2_Benchmark.xml', 'Mozilla Firefox for Windows STIG SCAP Benchmark', '6.4', '2023-06-05');
        $this->xml('U_MS_Windows_10_V3R5_STIG_SCAP_1-2_Benchmark.xml', 'Windows 10 STIG SCAP Benchmark', '3.5', '2025-02-25');

        $toc = (new ScapTocBuilder($this->dir))->rebuildAll();
        $this->assertSame(['Mozilla_Firefox_for_Linux_STIG_SCAP_Benchmark', 'Mozilla_Firefox_for_Windows_STIG_SCAP_Benchmark', 'Windows_10_STIG_SCAP_Benchmark'], array_keys($toc));
        $this->assertSame(['6.3 U_MOZ_Firefox_Linux_V6R3_STIG_SCAP_1-2_Benchmark.xml', '1.5 U_Mozilla_Firefox_RHEL_V1R5_STIG_SCAP_1-2_Benchmark.xml'],
            $this->order('Mozilla_Firefox_for_Linux_STIG_SCAP_Benchmark'));
        $this->assertSame(['6.4', '6.3', '1.5'], array_map(fn($r) => strtok($r, ' '), $this->order('Mozilla_Firefox_for_Windows_STIG_SCAP_Benchmark')));
    }

    public function testRetiredFirefoxTitlesPointAtThePlatformThatHasTheRelease(): void
    {
        $builder = new ScapTocBuilder($this->dir);
        $toc = [
            'Mozilla_Firefox_for_Linux_STIG_SCAP_Benchmark' => [['version' => '6', 'release' => '2']],
            'Mozilla_Firefox_for_Windows_STIG_SCAP_Benchmark' => [(object) ['version' => '6', 'release' => '2'], (object) ['version' => '1', 'release' => '3']],
        ];
        $this->assertSame('Mozilla_Firefox_for_Linux_STIG_SCAP_Benchmark', $builder->platformRedirect('Mozilla_Firefox', '6', '2', $toc));
        $this->assertSame('Mozilla_Firefox_for_Windows_STIG_SCAP_Benchmark', $builder->platformRedirect('Mozilla_FireFox', '1', '3', $toc));
        $this->assertNull($builder->platformRedirect('Mozilla_Firefox', '9', '9', $toc));
        $this->assertNull($builder->platformRedirect('Windows_10_STIG_SCAP_Benchmark', '6', '2', $toc), 'only retired titles');
    }
}
