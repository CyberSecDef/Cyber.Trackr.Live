<?php

namespace App\Tests\Service;

use App\Service\Changelog;
use PHPUnit\Framework\TestCase;

class ChangelogTest extends TestCase
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

    private function tmpDir(): string
    {
        $d = sys_get_temp_dir() . '/cltest_' . uniqid('', true);
        mkdir($d, 0777, true);
        $this->tmpDirs[] = $d;
        return $d;
    }

    private function writeChangelog(string $projectDir, string $rawJson): void
    {
        $dir = $projectDir . '/resources/data';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/changelog.json', $rawJson);
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

    public function testFrozenPathIsUnderProjectDir(): void
    {
        $d = $this->tmpDir();
        $this->assertSame($d . '/resources/data/changelog.json', (new Changelog($d))->frozenPath());
    }

    public function testLoadsFrozenEntries(): void
    {
        $d = $this->tmpDir();
        $this->writeChangelog($d, json_encode(['entries' => [
            ['hash' => 'abc123', 'subject' => 'first'],
            ['hash' => 'def456', 'subject' => 'second'],
        ]]));

        $entries = (new Changelog($d))->getEntries();
        $this->assertCount(2, $entries);
        $this->assertSame('abc123', $entries[0]['hash']);
        $this->assertSame('second', $entries[1]['subject']);
    }

    public function testMalformedJsonFallsThroughToEmpty(): void
    {
        // Invalid JSON → loadFrozen() returns null; the throwaway dir has no
        // .git, so the git fallback also yields nothing → empty array.
        $d = $this->tmpDir();
        $this->writeChangelog($d, 'not valid json {');
        $this->assertSame([], (new Changelog($d))->getEntries());
    }

    public function testMissingEntriesKeyFallsThroughToEmpty(): void
    {
        $d = $this->tmpDir();
        $this->writeChangelog($d, json_encode(['something_else' => true]));
        $this->assertSame([], (new Changelog($d))->getEntries());
    }

    public function testEmptyEntriesArrayIsReturnedAsIs(): void
    {
        $d = $this->tmpDir();
        $this->writeChangelog($d, json_encode(['entries' => []]));
        $this->assertSame([], (new Changelog($d))->getEntries());
    }
}
