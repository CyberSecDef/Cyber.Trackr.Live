<?php

namespace App\Tests\Service\Vulns;

use App\Service\Vulns\KevLoader;
use PHPUnit\Framework\TestCase;

class KevLoaderTest extends TestCase
{
    /** @var string[] */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $d) {
            $this->rrmdir($d);
        }
        $this->tmpDirs = [];
    }

    private function tmpDir(): string
    {
        $d = sys_get_temp_dir() . '/kevtest_' . uniqid('', true);
        mkdir($d, 0777, true);
        $this->tmpDirs[] = $d;
        return $d;
    }

    private function writeKev(string $projectDir, array $catalog): void
    {
        $dir = $projectDir . '/resources/data/kev';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/known_exploited_vulnerabilities.json', json_encode($catalog));
    }

    private function rrmdir(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $d . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($d);
    }

    public function testCatalogPathPointsAtMirror(): void
    {
        $d = $this->tmpDir();
        $this->assertSame(
            $d . '/resources/data/kev/known_exploited_vulnerabilities.json',
            (new KevLoader($d))->catalogPath()
        );
    }

    public function testMissingFileDegradesGracefully(): void
    {
        $k = new KevLoader($this->tmpDir());
        $this->assertFalse($k->exists());
        $this->assertNull($k->load());
        $this->assertSame([], $k->all());
        $this->assertSame([], $k->meta());
        $this->assertNull($k->byCve('CVE-2024-0001'));
    }

    public function testLoadAndMeta(): void
    {
        $d = $this->tmpDir();
        $this->writeKev($d, [
            'title'          => 'CISA KEV',
            'catalogVersion' => '2026.05.01',
            'dateReleased'   => '2026-05-01',
            'count'          => 2,
            'vulnerabilities' => [['cveID' => 'CVE-2024-0001'], ['cveID' => 'CVE-2024-0002']],
        ]);
        $k = new KevLoader($d);

        $this->assertTrue($k->exists());
        $meta = $k->meta();
        $this->assertSame('CISA KEV', $meta['title']);
        $this->assertSame('2026.05.01', $meta['catalogVersion']);
        $this->assertSame(2, $meta['count']);
        $this->assertCount(2, $k->all());
    }

    public function testByCveIsCaseInsensitiveAndTrimmed(): void
    {
        $d = $this->tmpDir();
        $this->writeKev($d, ['vulnerabilities' => [['cveID' => 'CVE-2024-0001', 'product' => 'Windows']]]);
        $k = new KevLoader($d);

        $this->assertSame('Windows', $k->byCve('cve-2024-0001')['product']);
        $this->assertSame('Windows', $k->byCve('  CVE-2024-0001 ')['product']);
        $this->assertNull($k->byCve('CVE-9999-9999'));
        $this->assertNull($k->byCve(''));
    }

    public function testSummaryComputesDeterministicStats(): void
    {
        $d = $this->tmpDir();
        // All dateAdded values are far in the past (so added_30d / added_this_year
        // are stably 0); dueDates use far-past vs far-future so active_deadlines
        // is stable regardless of when the test runs.
        $this->writeKev($d, ['vulnerabilities' => [
            ['cveID' => 'CVE-2000-0001', 'vendorProject' => 'Microsoft', 'dateAdded' => '2000-01-01', 'dueDate' => '2000-02-01', 'knownRansomwareCampaignUse' => 'Known'],
            ['cveID' => 'CVE-2000-0002', 'vendorProject' => 'Microsoft', 'dateAdded' => '2000-03-01', 'dueDate' => '2999-01-01', 'knownRansomwareCampaignUse' => 'Unknown'],
            ['cveID' => 'CVE-2001-0003', 'vendorProject' => 'Adobe',     'dateAdded' => '2001-01-01', 'dueDate' => '2998-01-01', 'knownRansomwareCampaignUse' => 'Known'],
        ]]);
        $s = (new KevLoader($d))->summary();

        $this->assertSame(3, $s['total']);
        $this->assertSame(2, $s['ransomware']);
        $this->assertSame(0, $s['added_30d']);
        $this->assertSame(0, $s['added_this_year']);
        $this->assertSame(2, $s['active_deadlines']);
        $this->assertSame(['Microsoft' => 2, 'Adobe' => 1], $s['top_vendors']);
        $this->assertSame('2001-01-01', $s['latest_added']);
    }
}
