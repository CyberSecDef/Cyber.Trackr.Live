<?php

namespace App\Tests\Service;

use App\Service\OverlayLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OverlayLoaderTest extends TestCase
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

    // ---- normalize() is a pure static — exercise it directly ----------------

    #[DataProvider('normalizeCases')]
    public function testNormalize(string $input, ?string $expected): void
    {
        $this->assertSame($expected, OverlayLoader::normalize($input));
    }

    public static function normalizeCases(): array
    {
        return [
            'base'              => ['AC-2', 'ac-2'],
            'enhancement'       => ['AC-2(1)', 'ac-2.1'],
            'enhancement-space' => ['AC-2 (1)', 'ac-2.1'],
            'whitespace'        => ['  AU-12  ', 'au-12'],
            'statement-letter'  => ['AC-1a.', null],
            'sub-statement'     => ['AC-1a.1.(b)', null],
            'garbage'           => ['not a control', null],
        ];
    }

    // ---- overlay loading from a temp dir ------------------------------------

    public function testGetControlOverlaysMapsMembership(): void
    {
        $dir = $this->tmpDir();
        $this->writeProfile($dir, 'NIST_rev5_LOW-baseline_profile.json', 'NIST Low', ['ac-1', 'ac-2', 'ac-2.1']);
        $this->writeProfile($dir, 'FedRAMP_rev5_MODERATE-baseline_profile.json', 'FedRAMP Moderate', ['ac-1']);
        $loader = new OverlayLoader($dir);

        $this->assertContains('nist-low', $loader->getControlOverlays('AC-1'));
        $this->assertContains('fedramp-moderate', $loader->getControlOverlays('AC-1'));
        // Only NIST includes the enhancement ac-2.1.
        $this->assertSame(['nist-low'], $loader->getControlOverlays('AC-2(1)'));
        // Enhancement membership also keeps the parent row visible.
        $this->assertContains('nist-low', $loader->getControlOverlays('AC-2'));
        $this->assertSame([], $loader->getControlOverlays('AC-99'));
        // Statement-letter forms are never overlay-eligible.
        $this->assertSame([], $loader->getControlOverlays('AC-1a.'));
    }

    public function testGetOverlaysMetadataAndOrder(): void
    {
        $dir = $this->tmpDir();
        $this->writeProfile($dir, 'FedRAMP_rev5_MODERATE-baseline_profile.json', 'FR Mod', ['ac-1']);
        $this->writeProfile($dir, 'NIST_rev5_LOW-baseline_profile.json', 'NIST Low', ['ac-1', 'ac-2']);
        $overlays = (new OverlayLoader($dir))->getOverlays();

        $this->assertSame(['nist-low', 'fedramp-moderate'], array_keys($overlays), 'NIST sorts before FedRAMP');
        $this->assertSame('NL', $overlays['nist-low']['abbr']);
        $this->assertSame('NIST Low', $overlays['nist-low']['short_title']);
        $this->assertSame(2, $overlays['nist-low']['count']);
        $this->assertSame('FM', $overlays['fedramp-moderate']['abbr']);
    }

    public function testDirPath(): void
    {
        $dir = $this->tmpDir();
        $this->assertSame($dir . '/resources/data/overlays', (new OverlayLoader($dir))->dir());
    }

    public function testEmptyDirYieldsNoOverlays(): void
    {
        $loader = new OverlayLoader($this->tmpDir());
        $this->assertSame([], $loader->getOverlays());
        $this->assertSame([], $loader->getControlOverlays('AC-1'));
    }

    // ---- helpers ------------------------------------------------------------

    private function tmpDir(): string
    {
        $d = sys_get_temp_dir() . '/ovltest_' . uniqid('', true);
        mkdir($d, 0777, true);
        $this->tmpDirs[] = $d;
        return $d;
    }

    private function writeProfile(string $projectDir, string $filename, string $title, array $controlIds): void
    {
        $dir = $projectDir . '/resources/data/overlays';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $doc = ['profile' => [
            'metadata' => ['title' => $title],
            'imports'  => [['include-controls' => [['with-ids' => $controlIds]]]],
        ]];
        file_put_contents($dir . '/' . $filename, json_encode($doc));
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
