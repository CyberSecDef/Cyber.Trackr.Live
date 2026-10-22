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

    /** A ZIP that ships a byte-identical alias's filename still serves the entry. */
    public function testIndexMapsAliasZipsToTheTocEntryPreferringItsOwnName(): void
    {
        $zips = $this->dir . '/resources/data/zips';
        file_put_contents($this->dir . '/resources/data/stig_toc.json', json_encode([
            'A' => [['version' => '1', 'release' => '1', 'filename' => 'a.xml', 'aliases' => ['a-copy.xml']]],
            'B' => [['version' => '1', 'release' => '1', 'filename' => 'b.xml', 'aliases' => ['b-copy.xml']]],
        ]));
        $this->zip("$zips/U_A_Y24M01_STIG.zip", 'a-copy.xml');   // only the alias
        $this->zip("$zips/U_B_Y20M01_STIG.zip", 'b-copy.xml');   // alias ...
        $this->zip("$zips/U_B_Y21M01_STIG.zip", 'b.xml');        // ... and own name: own wins
        $this->zip("$zips/U_B_Y22M01_STIG.zip", 'b-copy.xml');

        $finder = $this->finder();
        $finder->rebuildIndex();
        $index = json_decode(file_get_contents($finder->indexPath()), true);

        $this->assertSame(['a.xml' => 'U_A_Y24M01_STIG.zip', 'b.xml' => 'U_B_Y21M01_STIG.zip'], $index);
    }

    /** An unchanged XML re-bundled into later quarterly ZIPs: the newest bundle wins. */
    public function testIndexPrefersTheNewestBundle(): void
    {
        $zips = $this->dir . '/resources/data/zips';
        file_put_contents($this->dir . '/resources/data/stig_toc.json', json_encode([
            'A' => [['version' => '1', 'release' => '1', 'filename' => 'a.xml']],
            'Z' => [['version' => '1', 'release' => '1', 'filename' => 'z.xml']],
        ]));
        foreach (['U_A_Y24M10_STIG.zip', 'U_A_Y25M04_STIG.zip', 'U_A_Y24M01_STIG.zip'] as $name) {
            $this->zip("$zips/$name", 'a.xml');
        }
        foreach (['U_zOS_V6R9_Products.zip', 'U_zOS_V6R10_Products.zip'] as $name) {
            $this->zip("$zips/$name", 'z.xml');
        }

        $finder = $this->finder();
        $finder->rebuildIndex();
        $index = json_decode(file_get_contents($finder->indexPath()), true);

        $this->assertSame('U_A_Y25M04_STIG.zip', $index['a.xml']);
        $this->assertSame('U_zOS_V6R10_Products.zip', $index['z.xml'], 'numbers compare as numbers');
    }

    private function zip(string $path, string $entry): void
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString($entry, '<x/>');
        $zip->close();
    }

    public function testInvalidParamsAreStillRejectedWithRows(): void
    {
        $this->assertNull($this->finder()->resolveZipPath('../etc', '1', '2', $this->rows()));
    }
}
