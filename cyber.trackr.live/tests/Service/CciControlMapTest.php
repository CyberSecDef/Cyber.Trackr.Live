<?php

namespace App\Tests\Service;

use App\Service\CciControlMap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class CciControlMapTest extends TestCase
{
    private const REV3 = ['3', 'NIST SP 800-53'];
    private const REV4 = ['4', 'NIST SP 800-53 Revision 4'];
    private const REV5 = ['5', 'NIST SP 800-53 Revision 5'];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ccimap_' . uniqid('', true);
        mkdir($this->dir . '/resources/data/cci', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->path('U_CCI_List_2024.xml'));
        @unlink($this->path('U_CCI_List.xml'));
        @rmdir($this->dir . '/resources/data/cci');
        @rmdir($this->dir . '/resources/data');
        @rmdir($this->dir . '/resources');
        @rmdir($this->dir);
    }

    private function path(string $file): string
    {
        return $this->dir . '/resources/data/cci/' . $file;
    }

    /**
     * @param array<string, list<array{0:array{0:string,1:string},1:string}>> $items
     *        CCI id => [[revision, index], ...] in document order; "CCI-x#2" repeats an id
     */
    private function writeList(string $file, array $items): void
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><cci_list xmlns="http://iase.disa.mil/cci"><cci_items>';
        foreach ($items as $id => $refs) {
            $xml .= '<cci_item id="' . preg_replace('/#.*/', '', $id) . '"><references>';
            foreach ($refs as [[$version, $title], $index]) {
                $xml .= sprintf('<reference creator="NIST" title="%s" version="%s" index="%s" />', $title, $version, $index);
            }
            $xml .= '</references></cci_item>';
        }
        file_put_contents($this->path($file), $xml . '</cci_items></cci_list>');
    }

    public function testPrefersRev5ThenTodaysLinkThenFirstReference(): void
    {
        $this->writeList('U_CCI_List.xml', [
            'CCI-001196' => [[self::REV3, 'SC-26 (1)']],
            'CCI-001148' => [[self::REV3, 'SC-13 (4)'], [self::REV3, 'AU-10 (5)']],
            'CCI-000054' => [[self::REV3, 'AC-10']],
        ]);
        $this->writeList('U_CCI_List_2024.xml', [
            // Rev 5 wins over everything, even when listed last.
            'CCI-001196' => [[self::REV3, 'SC-26 (1)'], [self::REV4, 'SC-35'], [self::REV5, 'SC-35']],
            // No Rev 5: keep the legacy file's first reference, even though
            // the 2024 file orders this CCI's references differently.
            'CCI-001148' => [[self::REV3, 'AU-10 (5)'], [self::REV3, 'SC-13 (4)']],
            'CCI-000054' => [[self::REV3, 'AC-10'], [self::REV5, 'AC-10']],
            // Only in the 2024 file, no Rev 5: its own first reference.
            'CCI-003000' => [[self::REV4, 'PM-16 (1)']],
            // DISA wrote one Rev 5 index as a list.
            'CCI-002523' => [[self::REV4, 'SC-37'], [self::REV5, 'SC-37, SC-37 (1)']],
            'CCI-004066' => [[self::REV5, 'IA-5 (1) (h)']],
            'CCI-000002' => [],
            'CCI-004066#2' => [[self::REV5, 'ZZ-9']],
        ]);
        $map = new CciControlMap($this->dir, new ArrayAdapter());

        $this->assertSame('SC-35', $map->get('CCI-001196'));
        $this->assertSame('SC-13', $map->get('CCI-001148'));
        $this->assertSame('AC-10', $map->get('CCI-000054'));
        $this->assertSame('PM-16', $map->get('CCI-003000'));
        $this->assertSame('SC-37', $map->get('CCI-002523'));
        $this->assertSame('IA-5', $map->get('CCI-004066'), 'first occurrence of a duplicate id wins');
        $this->assertNull($map->get('CCI-000002'), 'no references -> no control');
        $this->assertNull($map->get('CCI-999999'));
    }

    public function testWorksWithoutTheLegacyList(): void
    {
        $this->writeList('U_CCI_List_2024.xml', [
            'CCI-001148' => [[self::REV3, 'AU-10 (5)'], [self::REV3, 'SC-13 (4)']],
        ]);
        $this->assertSame('AU-10', (new CciControlMap($this->dir, new ArrayAdapter()))->get('CCI-001148'));
    }

    public function testCacheIsKeyedOnTheFilesSoAnUpdatedListIsPickedUp(): void
    {
        $cache = new ArrayAdapter();
        $this->writeList('U_CCI_List_2024.xml', ['CCI-000015' => [[self::REV5, 'AC-2 (1)']]]);
        $this->assertSame('AC-2', (new CciControlMap($this->dir, $cache))->get('CCI-000015'));

        $this->writeList('U_CCI_List_2024.xml', ['CCI-000015' => [[self::REV5, 'AC-6 (1)']]]);
        touch($this->path('U_CCI_List_2024.xml'), time() + 5);
        clearstatcache();
        $this->assertSame('AC-6', (new CciControlMap($this->dir, $cache))->get('CCI-000015'));
    }

    public function testMissingFileGivesEmptyMap(): void
    {
        $this->assertSame([], (new CciControlMap($this->dir, new ArrayAdapter()))->all());
    }
}
