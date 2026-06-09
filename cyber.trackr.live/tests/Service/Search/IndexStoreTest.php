<?php

namespace App\Tests\Service\Search;

use App\Service\Search\IndexStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

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

    private function store(string $projectDir): IndexStore
    {
        // ArrayAdapter is an in-memory Symfony CacheInterface — no kernel needed.
        return new IndexStore($projectDir, new ArrayAdapter());
    }

    public function testDirPath(): void
    {
        $dir = $this->tmpDir();
        $this->assertSame($dir . '/resources/data/search', $this->store($dir)->dir());
    }

    public function testExistsReflectsShardsDir(): void
    {
        $dir = $this->tmpDir();
        $store = $this->store($dir);
        $this->assertFalse($store->exists());
        $store->saveAllShards([['id' => 'a']]);
        $this->assertTrue($store->exists());
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $dir = $this->tmpDir();
        $store = $this->store($dir);
        $store->save(IndexStore::FILE_POSTINGS, ['windows' => [0, 1], 'server' => [0]]);

        $this->assertSame(['windows' => [0, 1], 'server' => [0]], $store->postings());
    }

    public function testMissingFileLoadsAsEmpty(): void
    {
        $store = $this->store($this->tmpDir());
        $this->assertSame([], $store->postings());
        $this->assertSame([], $store->trigrams());
        $this->assertSame([], $store->sources());
    }

    public function testGetDocsLoadsOnlyRequestedAcrossShards(): void
    {
        $dir = $this->tmpDir();
        $store = $this->store($dir);

        // 1500 docs spans two shards (SHARD_SIZE = 1000).
        $docs = [];
        for ($i = 0; $i < 1500; $i++) {
            $docs[] = ['id' => 'doc-' . $i];
        }
        $store->saveAllShards($docs);

        $got = $store->getDocs([0, 1000, 1499]);
        $this->assertSame('doc-0', $got[0]['id']);
        $this->assertSame('doc-1000', $got[1000]['id']);   // shard 1, local 0
        $this->assertSame('doc-1499', $got[1499]['id']);   // shard 1, local 499
        $this->assertCount(3, $got);
    }

    public function testGetDocsEmptyAndMissing(): void
    {
        $dir = $this->tmpDir();
        $store = $this->store($dir);
        $store->saveAllShards([['id' => 'only']]);

        $this->assertSame([], $store->getDocs([]));
        $this->assertSame([], $store->getDocs([99999]));
    }

    public function testLoadAllShardsReturnsFlatArray(): void
    {
        $dir = $this->tmpDir();
        $store = $this->store($dir);
        $store->saveAllShards([['id' => 'a'], ['id' => 'b'], ['id' => 'c']]);

        $all = $store->loadAllShards();
        $this->assertCount(3, $all);
        $this->assertSame(['a', 'b', 'c'], array_column($all, 'id'));
    }

    // ---- helpers ------------------------------------------------------------

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
