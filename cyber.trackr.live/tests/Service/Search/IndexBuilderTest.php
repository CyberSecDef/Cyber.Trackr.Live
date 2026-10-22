<?php

namespace App\Tests\Service\Search;

use App\Service\Search\IndexBuilder;
use App\Service\Search\IndexStore;
use App\Service\Search\QueryParser;
use App\Service\Search\Searcher;
use App\Service\Search\Tokenizer;
use PHPUnit\Framework\TestCase;

/**
 * Review P2: the nightly delta (syncDeltas) must leave the index answering
 * every query exactly as a full rebuild of the same data would.
 */
class IndexBuilderTest extends TestCase
{
    /** @var string[] */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $d) {
            exec('rm -rf ' . escapeshellarg($d));
        }
    }

    private function project(): string
    {
        $d = sys_get_temp_dir() . '/ibtest_' . uniqid('', true);
        mkdir($d . '/resources/data/stig', 0777, true);
        $this->tmpDirs[] = $d;
        return $d;
    }

    /** @param array<string, string> $rules group id => rule title */
    private function stig(string $dir, string $file, array $rules): void
    {
        $groups = '';
        foreach ($rules as $gid => $title) {
            $groups .= "<Group id=\"$gid\"><Rule id=\"S$gid" . "r1_rule\"><title>$title</title><description>$title details</description></Rule></Group>";
        }
        file_put_contents("$dir/resources/data/stig/$file",
            "<?xml version=\"1.0\"?><Benchmark xmlns=\"http://checklists.nist.gov/xccdf/1.1\">$groups</Benchmark>");
    }

    private function toc(string $dir, array $toc): void
    {
        file_put_contents("$dir/resources/data/stig_toc.json", json_encode($toc));
    }

    private function builder(string $dir): IndexBuilder
    {
        return new IndexBuilder($dir, new Tokenizer(), new IndexStore($dir));
    }

    /** Result ids per section plus totals: what the search page shows. */
    private function results(string $dir, string $q): array
    {
        $out = (new Searcher(new IndexStore($dir), new QueryParser(new Tokenizer())))->search($q);
        $ids = [];
        foreach (['rmfv4', 'rmfv5', 'ccis', 'aps', 'vulns'] as $b) {
            $ids[$b] = array_map(fn($d) => ($d['stig_title'] ?? '') . ':' . $d['id'], $out[$b]);
        }
        return $ids + ['counts' => $out['counts']];
    }

    private function writeVersion1(string $dir): void
    {
        $this->stig($dir, 'A_V1R1.xml', ['V-1' => 'ssh root login disabled', 'V-2' => 'audit logs retained']);
        $this->toc($dir, ['Alpha' => [['filename' => 'A_V1R1.xml', 'version' => '1', 'release' => '1', 'released' => '2025-01-01', 'date' => '2025-01-01']]]);
    }

    private function writeVersion2(string $dir): void
    {
        $this->stig($dir, 'A_V1R2.xml', ['V-1' => 'ssh root login disabled', 'V-3' => 'firewall enabled']);
        $this->stig($dir, 'B_V1R1.xml', ['V-9' => 'ssh banner shown', 'V-10' => 'audit retention']);
        $this->toc($dir, [
            'Alpha' => [
                ['filename' => 'A_V1R1.xml', 'version' => '1', 'release' => '1', 'released' => '2025-01-01', 'date' => '2025-01-01'],
                ['filename' => 'A_V1R2.xml', 'version' => '1', 'release' => '2', 'released' => '2026-06-01', 'date' => '2026-06-01'],
            ],
            'Bravo' => [['filename' => 'B_V1R1.xml', 'version' => '1', 'release' => '1', 'released' => '2026-03-01', 'date' => '2026-03-01']],
        ]);
    }

    public function testDeltaMatchesAFullRebuild(): void
    {
        $delta = $this->project();
        $this->writeVersion1($delta);
        $this->builder($delta)->buildAll();
        $this->writeVersion2($delta);
        $stats = $this->builder($delta)->syncDeltas();
        $this->assertSame(['unchanged' => 0, 'changed' => 0, 'new' => 2, 'removed' => 1],
            array_intersect_key($stats, array_flip(['unchanged', 'changed', 'new', 'removed'])));

        $full = $this->project();
        $this->writeVersion2($full);
        $this->builder($full)->buildAll();

        foreach (['ssh', 'audit', 'firewall', 'V-1', 'V-2', 'V-3', '"root login"', 'sshh', 'retained', 'nomatch'] as $q) {
            $this->assertSame($this->results($full, $q), $this->results($delta, $q), "query: $q");
        }
        // The superseded release is gone, the new title is in, newest first.
        $this->assertSame(['Alpha:V-1', 'Bravo:V-9'], $this->results($delta, 'ssh')['vulns']);
        $this->assertSame([], $this->results($delta, 'V-2')['vulns']);
    }

    public function testDeltaWithNoChangesKeepsTheLiveBuild(): void
    {
        $dir = $this->project();
        $this->writeVersion1($dir);
        $first = $this->builder($dir)->buildAll()['build'];

        $stats = $this->builder($dir)->syncDeltas();

        $this->assertSame(1, $stats['unchanged']);
        $this->assertSame($first, (new IndexStore($dir))->currentBuild());
        $this->assertCount(1, glob("$dir/resources/data/search/build-*", GLOB_ONLYDIR));
    }
}
