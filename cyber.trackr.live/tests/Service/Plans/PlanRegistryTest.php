<?php

namespace App\Tests\Service\Plans;

use App\Service\Plans\PlanRegistry;
use PHPUnit\Framework\TestCase;

class PlanRegistryTest extends TestCase
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

    public function testAvailablePlansDiscoversFamiliesAndExcludesShared(): void
    {
        $dir = $this->tmpDir();
        $this->writeSchema($dir, 'cm.json', ['family' => 'CM', 'family_name' => 'Configuration Management', 'title' => 'CM Plan', 'description' => 'd']);
        $this->writeSchema($dir, 'ac.json', ['family' => 'AC', 'family_name' => 'Access Control', 'title' => 'AC Plan', 'description' => 'a']);
        $this->writeSchema($dir, '_shared.json', ['glossary' => []]);

        $plans = (new PlanRegistry($dir))->availablePlans();

        $this->assertSame(['ac', 'cm'], array_keys($plans), 'ksorted, _shared excluded');
        $this->assertSame('Access Control', $plans['ac']['family_name']);
        $this->assertSame('CM Plan', $plans['cm']['title']);
    }

    public function testHasIsCaseInsensitive(): void
    {
        $dir = $this->tmpDir();
        $this->writeSchema($dir, 'ac.json', ['family' => 'AC']);
        $registry = new PlanRegistry($dir);

        $this->assertTrue($registry->has('ac'));
        $this->assertTrue($registry->has('AC'));
        $this->assertFalse($registry->has('zz'));
    }

    public function testGetSchema(): void
    {
        $dir = $this->tmpDir();
        $this->writeSchema($dir, 'ac.json', ['family' => 'AC', 'title' => 'AC Plan']);
        $registry = new PlanRegistry($dir);

        $this->assertSame('AC Plan', $registry->getSchema('ac')['title']);
        $this->assertSame('AC Plan', $registry->getSchema('AC')['title']);
        $this->assertNull($registry->getSchema('zz'));
    }

    public function testGetShared(): void
    {
        $dir = $this->tmpDir();
        $this->writeSchema($dir, '_shared.json', ['acronyms' => ['ODV' => 'organization-defined value']]);
        $this->assertSame(['acronyms' => ['ODV' => 'organization-defined value']], (new PlanRegistry($dir))->getShared());
    }

    public function testEmptyDirYieldsNothing(): void
    {
        $registry = new PlanRegistry($this->tmpDir());
        $this->assertSame([], $registry->availablePlans());
        $this->assertSame([], $registry->getShared());
        $this->assertFalse($registry->has('ac'));
    }

    // ---- helpers ------------------------------------------------------------

    private function tmpDir(): string
    {
        $d = sys_get_temp_dir() . '/prtest_' . uniqid('', true);
        mkdir($d . '/resources/data/plans', 0777, true);
        $this->tmpDirs[] = $d;
        return $d;
    }

    private function writeSchema(string $projectDir, string $file, array $schema): void
    {
        file_put_contents($projectDir . '/resources/data/plans/' . $file, json_encode($schema));
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
}
