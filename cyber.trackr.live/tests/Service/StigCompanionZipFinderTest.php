<?php

namespace App\Tests\Service;

use App\Service\StigCompanionZipFinder;
use PHPUnit\Framework\TestCase;

/**
 * Review P15: the STIG view hands the finder the TOC rows it already decoded
 * instead of the finder reading the 1.4 MB stig_toc.json a second time. The
 * rows must resolve exactly as the file would, including the first-match rule
 * for a release listed twice (the companion.zip/.pdf routes use the file).
 */
class StigCompanionZipFinderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/czf_' . uniqid('', true);
        mkdir($this->dir . '/resources/data/zips', 0777, true);
        file_put_contents($this->dir . '/resources/data/companion_zip_index.json', json_encode([
            'first.xml'  => 'U_First_STIG.zip',
            'second.xml' => 'U_Second_STIG.zip',
        ]));
        file_put_contents($this->dir . '/resources/data/stig_toc.json', json_encode(['T' => $this->rows()]));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function rows(): array
    {
        return [
            ['version' => '1', 'release' => '2', 'filename' => 'first.xml'],
            ['version' => '1', 'release' => '2', 'filename' => 'second.xml'], // same release, listed twice
            ['version' => '1', 'release' => '1', 'filename' => 'older.xml'],
        ];
    }

    private function finder(): StigCompanionZipFinder
    {
        return new StigCompanionZipFinder($this->dir);
    }

    public function testRowsResolveLikeTheTocFileFirstMatchWins(): void
    {
        $zips = $this->dir . '/resources/data/zips/';
        $this->assertSame($zips . 'U_First_STIG.zip', $this->finder()->resolveZipPath('T', '1', '2'));
        $this->assertSame($zips . 'U_First_STIG.zip', $this->finder()->resolveZipPath('T', '1', '2', $this->rows()));
        // The controller decodes the TOC to objects.
        $objects = json_decode(json_encode($this->rows()));
        $this->assertSame($zips . 'U_First_STIG.zip', $this->finder()->resolveZipPath('T', '1', '2', $objects));
        $this->assertNull($this->finder()->resolveZipPath('T', '1', '1', $this->rows()), 'no companion ZIP indexed');
    }

    public function testGivenRowsTheTocFileIsNotRead(): void
    {
        unlink($this->dir . '/resources/data/stig_toc.json');
        $this->assertNull($this->finder()->resolveZipPath('T', '1', '2'));
        $this->assertSame($this->dir . '/resources/data/zips/U_First_STIG.zip',
            $this->finder()->resolveZipPath('T', '1', '2', $this->rows()));
    }

    public function testInvalidParamsAreStillRejectedWithRows(): void
    {
        $this->assertNull($this->finder()->resolveZipPath('../etc', '1', '2', $this->rows()));
    }
}
