<?php

namespace App\Tests\Service\Search;

use App\Service\Search\IndexStore;
use App\Service\Search\QueryParser;
use App\Service\Search\Searcher;
use App\Service\Search\Tokenizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class SearcherTest extends TestCase
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

    /** Builds a tiny three-doc index and returns a Searcher over it. */
    private function searcher(): Searcher
    {
        $dir = $this->tmpDir();
        $store = new IndexStore($dir, new ArrayAdapter());

        $docs = [
            0 => ['type' => 'ccis',  'id' => 'CCI-000001', 'text' => 'windows server access control'],
            1 => ['type' => 'rmfv5', 'id' => 'AC-2',       'text' => 'account management'],
            2 => ['type' => 'vulns', 'id' => 'V-1',        'text' => 'windows defender', 'released' => '2024-01-01', 'stig_title' => 'X'],
        ];
        $store->save(IndexStore::FILE_POSTINGS, [
            'windows'    => [0, 2],
            'server'     => [0],
            'access'     => [0],
            'control'    => [0],
            'account'    => [1],
            'management' => [1],
            'defender'   => [2],
        ]);
        $store->save(IndexStore::FILE_TRIGRAMS, []);
        $store->saveAllShards($docs);

        return new Searcher($store, new QueryParser(new Tokenizer()));
    }

    public function testSingleTokenMatchesBuckets(): void
    {
        $out = $this->searcher()->search('windows');
        $this->assertCount(1, $out['ccis']);
        $this->assertSame('CCI-000001', $out['ccis'][0]['id']);
        $this->assertCount(1, $out['vulns']);
        $this->assertCount(0, $out['rmfv5']);
    }

    public function testTokensAreAndedTogether(): void
    {
        // "windows" -> {0,2}, "server" -> {0}; intersection is just doc 0.
        $out = $this->searcher()->search('windows server');
        $this->assertCount(1, $out['ccis']);
        $this->assertCount(0, $out['vulns']);
    }

    public function testNoMatchReturnsEmptyBuckets(): void
    {
        $out = $this->searcher()->search('zzznomatch');
        $this->assertSame(['rmfv4' => [], 'rmfv5' => [], 'ccis' => [], 'aps' => [], 'vulns' => []], $out);
    }

    public function testEmptyQueryReturnsEmptyBuckets(): void
    {
        $out = $this->searcher()->search('');
        $this->assertSame(['rmfv4' => [], 'rmfv5' => [], 'ccis' => [], 'aps' => [], 'vulns' => []], $out);
    }

    public function testPhraseMatchKeepsContiguousDoc(): void
    {
        $out = $this->searcher()->search('"access control"');
        $this->assertCount(1, $out['ccis']);
    }

    public function testPhraseFilterDropsNonContiguousDoc(): void
    {
        // Both tokens are in doc 2 ("windows defender"), but the literal phrase
        // "defender windows" does not appear, so the phrase filter excludes it.
        $out = $this->searcher()->search('"defender windows"');
        $this->assertCount(0, $out['vulns']);
    }

    // ---- helpers ------------------------------------------------------------

    private function tmpDir(): string
    {
        $d = sys_get_temp_dir() . '/srtest_' . uniqid('', true);
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
