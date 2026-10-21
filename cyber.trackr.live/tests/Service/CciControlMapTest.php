<?php

namespace App\Tests\Service;

use App\Service\CciControlMap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class CciControlMapTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ccimap_' . uniqid('', true);
        mkdir($this->dir . '/resources/data/cci', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->path());
        @rmdir($this->dir . '/resources/data/cci');
        @rmdir($this->dir . '/resources/data');
        @rmdir($this->dir . '/resources');
        @rmdir($this->dir);
    }

    private function path(): string
    {
        return $this->dir . '/resources/data/cci/U_CCI_List.xml';
    }

    /** @param array<string, list<string>> $items CCI id => reference indexes, in order */
    private function writeList(array $items): void
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><cci_list xmlns="http://iase.disa.mil/cci"><cci_items>';
        foreach ($items as $id => $indexes) {
            $id = preg_replace('/#.*/', '', $id); // allow duplicate ids in the fixture via "CCI-x#2"
            $xml .= '<cci_item id="' . $id . '"><references>';
            foreach ($indexes as $i => $index) {
                $xml .= '<reference creator="NIST" version="' . (3 + $i) . '" index="' . $index . '" />';
            }
            $xml .= '</references></cci_item>';
        }
        file_put_contents($this->path(), $xml . '</cci_items></cci_list>');
    }

    public function testMapsFirstReferenceToItsBaseControl(): void
    {
        $this->writeList([
            'CCI-000015' => ['AC-2 (1)', 'AC-2 (1).1'],
            'CCI-000054' => ['AC-10'],
            'CCI-000001' => ['AC-1 a', 'AC-1 a 1'],
            'CCI-000002' => [],
            'CCI-000015#2' => ['ZZ-9'],
        ]);
        $map = new CciControlMap($this->dir, new ArrayAdapter());

        $this->assertSame('AC-2', $map->get('CCI-000015'), 'first reference, split at the first space');
        $this->assertSame('AC-10', $map->get('CCI-000054'));
        $this->assertSame('AC-1', $map->get('CCI-000001'));
        $this->assertNull($map->get('CCI-000002'), 'no references -> no control');
        $this->assertNull($map->get('CCI-999999'));
        $this->assertSame(['CCI-000015' => 'AC-2', 'CCI-000054' => 'AC-10', 'CCI-000001' => 'AC-1'], $map->all(),
            'first occurrence of a duplicate id wins');
    }

    public function testCacheIsKeyedOnTheFileSoAnUpdatedListIsPickedUp(): void
    {
        $cache = new ArrayAdapter();
        $this->writeList(['CCI-000015' => ['AC-2 (1)']]);
        $this->assertSame('AC-2', (new CciControlMap($this->dir, $cache))->get('CCI-000015'));

        $this->writeList(['CCI-000015' => ['AC-6 (1)'], 'CCI-000016' => ['AC-7']]);
        touch($this->path(), time() + 5);
        clearstatcache();
        $this->assertSame('AC-6', (new CciControlMap($this->dir, $cache))->get('CCI-000015'));
    }

    public function testMissingFileGivesEmptyMap(): void
    {
        $map = new CciControlMap($this->dir, new ArrayAdapter());
        $this->assertSame([], $map->all());
    }
}
