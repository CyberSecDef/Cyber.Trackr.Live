<?php

namespace App\Tests\Service;

use App\Service\BulkDownloadIndex;
use App\Service\BulkDownloadPlanner;
use PHPUnit\Framework\TestCase;

class BulkDownloadPlannerTest extends TestCase
{
    private const MB = 1024 * 1024;

    private function planner(): BulkDownloadPlanner
    {
        // plan() is pure and never touches the index, so a bare mock suffices.
        return new BulkDownloadPlanner($this->createMock(BulkDownloadIndex::class));
    }

    /** @return array{kind:string,id:string,path:string,name:string,size:int} */
    private function file(int $size, string $id = 'stig::x'): array
    {
        return [
            'kind' => BulkDownloadPlanner::KIND_XML,
            'id'   => $id,
            'path' => '/tmp/' . $id,
            'name' => $id . '.xml',
            'size' => $size,
        ];
    }

    public function testEmptySelectionProducesNoChunks(): void
    {
        $plan = $this->planner()->plan([]);
        $this->assertSame([], $plan['chunks']);
        $this->assertSame(0, $plan['total_files']);
        $this->assertSame(0, $plan['total_bytes']);
    }

    public function testSmallFilesPackIntoOneChunk(): void
    {
        $plan = $this->planner()->plan([
            $this->file(10, 'a'),
            $this->file(20, 'b'),
            $this->file(30, 'c'),
        ]);

        $this->assertCount(1, $plan['chunks']);
        $this->assertSame(3, $plan['total_files']);
        $this->assertSame(60, $plan['total_bytes']);
        $this->assertSame(1, $plan['chunks'][0]['index']);
        $this->assertSame(1, $plan['chunks'][0]['of']);
        $this->assertCount(3, $plan['chunks'][0]['files']);
    }

    public function testFilesSplitWhenAddingWouldExceedCap(): void
    {
        $plan = $this->planner()->plan([
            $this->file(300 * self::MB, 'a'),
            $this->file(300 * self::MB, 'b'),
        ]);

        // 300 + 300 MB > 500 MB cap, so the second file starts a new chunk.
        $this->assertCount(2, $plan['chunks']);
        $this->assertCount(1, $plan['chunks'][0]['files']);
        $this->assertCount(1, $plan['chunks'][1]['files']);
        $this->assertSame(2, $plan['total_files']);
        $this->assertSame(600 * self::MB, $plan['total_bytes']);
    }

    public function testGreedyPackingFillsBeforeSplitting(): void
    {
        $plan = $this->planner()->plan([
            $this->file(200 * self::MB, 'a'),
            $this->file(200 * self::MB, 'b'),
            $this->file(200 * self::MB, 'c'),
        ]);

        // a+b = 400 MB fit together; c (would make 600) opens a second chunk.
        $this->assertCount(2, $plan['chunks']);
        $this->assertCount(2, $plan['chunks'][0]['files']);
        $this->assertCount(1, $plan['chunks'][1]['files']);
        $this->assertSame(2, $plan['chunks'][1]['of']);
    }

    public function testOversizedSingleFileGetsItsOwnChunk(): void
    {
        $plan = $this->planner()->plan([$this->file(600 * self::MB, 'big')]);

        $this->assertCount(1, $plan['chunks']);
        $this->assertSame(1, $plan['total_files']);
        $this->assertSame(600 * self::MB, $plan['total_bytes']);
    }
}
