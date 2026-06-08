<?php

namespace App\Tests\Service;

use App\Service\StigDigestBuilder;
use PHPUnit\Framework\TestCase;

class StigDigestBuilderTest extends TestCase
{
    /** @return array<int,array<string,string>> date-descending is NOT assumed; the SUT sorts. */
    private function entries(): array
    {
        return [
            ['version' => '2', 'release' => '1', 'filename' => 'v2r1.xml', 'date' => '2021-01-01', 'released' => 'Jan 2021'],
            ['version' => '1', 'release' => '3', 'filename' => 'v1r3.xml', 'date' => '2020-06-01', 'released' => 'Jun 2020'],
            ['version' => '1', 'release' => '1', 'filename' => 'v1r1.xml', 'date' => '2019-01-01', 'released' => 'Jan 2019'],
        ];
    }

    public function testReturnsImmediatelyOlderEntry(): void
    {
        $prev = (new StigDigestBuilder())->findPreviousEntry($this->entries(), '2', '1');
        $this->assertNotNull($prev);
        $this->assertSame('1', $prev['version']);
        $this->assertSame('3', $prev['release']);
        $this->assertSame('v1r3.xml', $prev['filename']);
    }

    public function testMiddleEntryPreviousIsTheOldest(): void
    {
        $prev = (new StigDigestBuilder())->findPreviousEntry($this->entries(), '1', '3');
        $this->assertSame('v1r1.xml', $prev['filename']);
    }

    public function testOldestEntryHasNoPrevious(): void
    {
        $this->assertNull((new StigDigestBuilder())->findPreviousEntry($this->entries(), '1', '1'));
    }

    public function testUnknownVersionReturnsNull(): void
    {
        $this->assertNull((new StigDigestBuilder())->findPreviousEntry($this->entries(), '9', '9'));
    }

    public function testSingleEntryReturnsNull(): void
    {
        $only = [['version' => '1', 'release' => '1', 'filename' => 'x.xml', 'date' => '2020-01-01']];
        $this->assertNull((new StigDigestBuilder())->findPreviousEntry($only, '1', '1'));
    }

    public function testIgnoresInputOrderAndSortsByDate(): void
    {
        // Deliberately oldest-first; the SUT must still resolve by date.
        $shuffled = [
            ['version' => '1', 'release' => '1', 'filename' => 'v1r1.xml', 'date' => '2019-01-01'],
            ['version' => '2', 'release' => '1', 'filename' => 'v2r1.xml', 'date' => '2021-01-01'],
            ['version' => '1', 'release' => '3', 'filename' => 'v1r3.xml', 'date' => '2020-06-01'],
        ];
        $prev = (new StigDigestBuilder())->findPreviousEntry($shuffled, '2', '1');
        $this->assertSame('v1r3.xml', $prev['filename']);
    }

    public function testAcceptsStdClassEntries(): void
    {
        $objs = array_map(fn($e) => (object) $e, $this->entries());
        $prev = (new StigDigestBuilder())->findPreviousEntry($objs, '2', '1');
        $this->assertSame('v1r3.xml', $prev['filename']);
    }

    public function testMatchesVersionReleaseAsStrings(): void
    {
        // version/release passed as ints must still match string TOC values.
        $prev = (new StigDigestBuilder())->findPreviousEntry($this->entries(), (string) 2, (string) 1);
        $this->assertSame('v1r3.xml', $prev['filename']);
    }
}
