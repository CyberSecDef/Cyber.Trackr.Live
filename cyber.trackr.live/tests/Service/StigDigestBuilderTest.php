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

    public function testSkipsDuplicateEntriesOfTheCurrentRelease(): void
    {
        // DISA occasionally ships one release under two filenames; the
        // duplicate must not be treated as "previous" (that yields a self-diff).
        $entries = [
            ['version' => '1', 'release' => '5', 'filename' => 'a_v1r5.xml', 'date' => '2020-06-02'],
            ['version' => '1', 'release' => '5', 'filename' => 'b_v1r5.xml', 'date' => '2020-06-01'],
            ['version' => '1', 'release' => '4', 'filename' => 'v1r4.xml',   'date' => '2020-01-01'],
        ];
        $prev = (new StigDigestBuilder())->findPreviousEntry($entries, '1', '5');
        $this->assertSame('v1r4.xml', $prev['filename']);
    }

    public function testOnlyDuplicatesMeansNoPrevious(): void
    {
        $entries = [
            ['version' => '1', 'release' => '5', 'filename' => 'a.xml', 'date' => '2020-06-02'],
            ['version' => '1', 'release' => '5', 'filename' => 'b.xml', 'date' => '2020-06-01'],
        ];
        $this->assertNull((new StigDigestBuilder())->findPreviousEntry($entries, '1', '5'));
    }

    public function testCachedDigestIsRebuiltWhenPreviousVersionChanges(): void
    {
        $dir = sys_get_temp_dir() . '/stig_digest_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents("$dir/v1r1.xml", $this->xccdf(['V-1']));
            file_put_contents("$dir/v1r3.xml", $this->xccdf(['V-1', 'V-2', 'V-3']));
            file_put_contents("$dir/v2r1.xml", $this->xccdf(['V-1', 'V-2', 'V-3']));
            // Keep every mtime equal so only the previous-file identity differs.
            foreach (['v1r1', 'v1r3', 'v2r1'] as $f) touch("$dir/$f.xml", 1_600_000_000);

            $current = ['version' => '2', 'release' => '1', 'filename' => 'v2r1.xml', 'date' => '2021-01-01', 'released' => ''];
            $builder = new StigDigestBuilder();

            // v1r3 not in the TOC yet: previous resolves to v1r1 → V-2, V-3 added.
            $withoutV1r3 = array_values(array_filter($this->entries(), fn($e) => $e['filename'] !== 'v1r3.xml'));
            $first = $builder->buildOrLoad($dir, $current, $withoutV1r3);
            $this->assertSame('1', $first['previous']['release']);
            $this->assertCount(2, $first['added']);

            // v1r3 lands in the TOC (pulled late). The cache must not be reused.
            $second = $builder->buildOrLoad($dir, $current, $this->entries());
            $this->assertSame('3', $second['previous']['release']);
            $this->assertCount(0, $second['added']);
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    /** @param string[] $groupIds */
    private function xccdf(array $groupIds): string
    {
        $groups = '';
        foreach ($groupIds as $id) {
            $groups .= "<Group id=\"$id\"><Rule id=\"S$id\" severity=\"medium\"><title>$id</title>"
                . "<description>d</description><fixtext>f</fixtext></Rule></Group>";
        }
        return '<?xml version="1.0"?><Benchmark xmlns="http://checklists.nist.gov/xccdf/1.1">' . $groups . '</Benchmark>';
    }
}
