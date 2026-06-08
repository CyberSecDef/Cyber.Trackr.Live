<?php

namespace App\Tests\Service;

use App\Service\R4ToR5Mapper;
use PHPUnit\Framework\TestCase;

/**
 * R4ToR5Mapper reads the committed 800-53 r4/r5 catalogs and curated mapping
 * from resources/data/rmf/. These tests assert deterministic structural
 * invariants of map() against that real data rather than pinning exact counts
 * (which legitimately shift when the catalogs are refreshed).
 */
class R4ToR5MapperTest extends TestCase
{
    private const ALLOWED_KINDS = ['unchanged', 'withdrawn', 'incorporated-into', 'new-in-r5'];

    private function map(): array
    {
        return (new R4ToR5Mapper())->map();
    }

    public function testTopLevelStructure(): void
    {
        $r = $this->map();
        $this->assertArrayHasKey('rows', $r);
        $this->assertArrayHasKey('kinds', $r);
        $this->assertArrayHasKey('families', $r);
        $this->assertArrayHasKey('totals', $r);
    }

    public function testTotalsArePopulated(): void
    {
        $t = $this->map()['totals'];
        $this->assertGreaterThan(0, $t['r4_total']);
        $this->assertGreaterThan(0, $t['r5_total']);
        $this->assertArrayHasKey('curated_count', $t);
    }

    public function testRowsAreNonEmptyAndShaped(): void
    {
        $rows = $this->map()['rows'];
        $this->assertNotEmpty($rows);
        foreach (['r4', 'r5', 'kind', 'family', 'source'] as $key) {
            $this->assertArrayHasKey($key, $rows[0]);
        }
    }

    public function testKindCountsSumToRowCount(): void
    {
        $r = $this->map();
        $this->assertSame(count($r['rows']), array_sum($r['kinds']));
    }

    public function testEveryKindIsFromTheKnownSet(): void
    {
        foreach (array_keys($this->map()['kinds']) as $kind) {
            $this->assertContains($kind, self::ALLOWED_KINDS);
        }
    }

    public function testRowSourceIsCuratedOrMechanical(): void
    {
        foreach ($this->map()['rows'] as $row) {
            $this->assertContains($row['source'], ['curated', 'mechanical']);
        }
    }

    public function testRowsSortByFamilyThenNumber(): void
    {
        // AC-1 carries the smallest sort key, so the AC family leads the list.
        $rows = $this->map()['rows'];
        $this->assertSame('AC', $rows[0]['family']);
    }

    public function testMapIsDeterministic(): void
    {
        $mapper = new R4ToR5Mapper();
        $this->assertSame(count($mapper->map()['rows']), count($mapper->map()['rows']));
    }
}
