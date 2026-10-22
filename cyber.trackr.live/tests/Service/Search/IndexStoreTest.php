<?php

namespace App\Tests\Service\Search;

use App\Service\Search\IndexStore;
use PHPUnit\Framework\TestCase;

class IndexStoreTest extends TestCase
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

    private function docs(): array
    {
        return [
            ['type' => 'ccis', 'id' => 'CCI-000001', 'text' => 'one'],
            null, // dropped by a delta
            ['type' => 'vulns', 'id' => 'V-1', 'stig_title' => 'Windows_11', 'released' => '2026-08-10', 'text' => 'two'],
            ['type' => 'vulns', 'id' => 'V-2', 'stig_title' => 'RHEL_9', 'released' => '', 'text' => 'three'],
        ];
    }

    public function testNothingBeforeTheFirstBuild(): void
    {
        $store = new IndexStore($this->tmpDir());
        $this->assertFalse($store->exists());
        $this->assertSame([], $store->postingsFor(['windows']));
        $this->assertSame([], $store->trigramsFor(['_wi']));
        $this->assertSame([], $store->sources());
        $this->assertSame(0, $store->docCount());
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $store = new IndexStore($this->tmpDir());
        $store->writeBuild($this->docs(), ['windows' => [0, 2], 'rhel' => [3], '2024' => [2]], ['_wi' => ['windows']], ['src.xml' => ['sha256' => 'x']]);

        $this->assertTrue($store->exists());
        $this->assertSame(['windows' => [0, 2], 'rhel' => [3]], $store->postingsFor(['windows', 'rhel', 'absent']));
        $this->assertSame([2], $store->postingsFor(['2024'])['2024'] ?? null, 'numeric-looking tokens');
        $this->assertSame(['_wi' => ['windows']], $store->trigramsFor(['_wi', '_zz']));
        $this->assertSame(['src.xml' => ['sha256' => 'x']], $store->sources());
        $this->assertSame(4, $store->docCount());
        $this->assertSame($this->docs(), $store->loadAllDocs());
    }

    public function testGetDocsKeepsRequestOrderAndSkipsDroppedOrUnknown(): void
    {
        $store = new IndexStore($this->tmpDir());
        $store->writeBuild($this->docs(), [], [], []);

        $got = $store->getDocs([3, 1, 0, 99]);
        $this->assertSame([3, 0], array_keys($got));
        $this->assertSame('V-2', $got[3]['id']);
        $this->assertSame([], $store->getDocs([]));
    }

    public function testMetaGivesTypeTimestampAndTitleWithoutReadingDocs(): void
    {
        $store = new IndexStore($this->tmpDir());
        $store->writeBuild($this->docs(), [], [], []);

        $this->assertSame(['type' => 'ccis', 'ts' => 0, 'title' => ''], $store->meta(0));
        $this->assertSame(['type' => null, 'ts' => 0, 'title' => ''], $store->meta(1));
        $this->assertSame(['type' => 'vulns', 'ts' => strtotime('2026-08-10'), 'title' => 'Windows_11'], $store->meta(2));
        $this->assertSame(['type' => 'vulns', 'ts' => 0, 'title' => 'RHEL_9'], $store->meta(3), 'unparseable date sorts last');
        $this->assertSame(['type' => null, 'ts' => 0, 'title' => ''], $store->meta(42));
    }

    public function testTokensAreSplitIntoBucketFiles(): void
    {
        $dir = $this->tmpDir();
        $store = new IndexStore($dir);
        $postings = [];
        foreach (range(1, 2000) as $i) {
            $postings["tok$i"] = [$i];
        }
        $store->writeBuild([], $postings, [], []);

        $build = $dir . '/resources/data/search/' . $store->currentBuild();
        $files = glob($build . '/postings/*.json');
        $this->assertGreaterThan(200, count($files), 'spread over many buckets');
        $this->assertSame([7], $store->postingsFor(['tok7'])['tok7']);
    }

    public function testNewBuildIsAtomicForReadersAlreadyInFlight(): void
    {
        $dir = $this->tmpDir();
        (new IndexStore($dir))->writeBuild([['type' => 'ccis', 'id' => 'OLD', 'text' => '']], ['x' => [0]], [], []);

        $reader = new IndexStore($dir);
        $this->assertSame('OLD', $reader->getDocs([0])[0]['id']); // reader is now bound to build 1

        (new IndexStore($dir))->writeBuild([['type' => 'ccis', 'id' => 'NEW', 'text' => '']], ['x' => [0]], [], []);

        $this->assertSame('OLD', $reader->getDocs([0])[0]['id'], 'an in-flight request keeps a consistent build');
        $this->assertSame('NEW', (new IndexStore($dir))->getDocs([0])[0]['id'], 'new requests see the new build');
    }

    public function testKeepsLiveAndPreviousBuildAndRemovesLegacyFiles(): void
    {
        $dir = $this->tmpDir();
        $search = $dir . '/resources/data/search';
        mkdir($search . '/docs', 0777, true);
        foreach (['postings.json', 'trigrams.json', 'sources.json', 'docs/0000.json'] as $legacy) {
            file_put_contents("$search/$legacy", '{}');
        }

        $store = new IndexStore($dir);
        $names = [];
        foreach (range(1, 3) as $i) {
            $names[] = $store->writeBuild([], ['x' => [$i]], [], []);
            usleep(1100000); // build names sort by second
        }

        $left = array_map('basename', glob($search . '/build-*', GLOB_ONLYDIR));
        sort($left);
        $this->assertSame([$names[1], $names[2]], $left);
        $this->assertSame($names[2], trim(file_get_contents($search . '/current')));
        foreach (['postings.json', 'trigrams.json', 'sources.json', 'docs'] as $legacy) {
            $this->assertFileDoesNotExist("$search/$legacy");
        }
    }

    private function tmpDir(): string
    {
        $d = sys_get_temp_dir() . '/istest_' . uniqid('', true);
        mkdir($d, 0777, true);
        $this->tmpDirs[] = $d;
        return $d;
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
