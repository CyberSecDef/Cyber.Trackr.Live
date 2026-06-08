<?php

namespace App\Tests\Service;

use App\Service\AppVersion;
use PHPUnit\Framework\TestCase;

class AppVersionTest extends TestCase
{
    /** @var string[] */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $d) {
            if (is_dir($d)) {
                foreach (scandir($d) as $f) {
                    if ($f !== '.' && $f !== '..') {
                        @unlink($d . '/' . $f);
                    }
                }
                @rmdir($d);
            }
        }
        $this->tmpDirs = [];
    }

    private function tmpDir(): string
    {
        $d = sys_get_temp_dir() . '/avtest_' . uniqid('', true);
        mkdir($d, 0777, true);
        $this->tmpDirs[] = $d;
        return $d;
    }

    public function testReadsBakedVersionFile(): void
    {
        $d = $this->tmpDir();
        file_put_contents($d . '/VERSION', '9.2601.42');
        $this->assertSame('9.2601.42', (new AppVersion($d))->get());
    }

    public function testTrimsWhitespaceFromVersionFile(): void
    {
        $d = $this->tmpDir();
        file_put_contents($d . '/VERSION', "  1.2.3\n");
        $this->assertSame('1.2.3', (new AppVersion($d))->get());
    }

    public function testEmptyVersionFileFallsThroughToCompute(): void
    {
        $d = $this->tmpDir();
        file_put_contents($d . '/VERSION', "   \n");
        // No VERSION value → live compute → MAJOR.YYMM.commits shape.
        $this->assertMatchesRegularExpression('/^3\.\d{4}\.\d+$/', (new AppVersion($d))->get());
    }

    public function testGetCachesFirstResult(): void
    {
        $d = $this->tmpDir();
        file_put_contents($d . '/VERSION', '5.5.5');
        $v = new AppVersion($d);
        $first = $v->get();
        // Mutating the file after the first read must not change the cached value.
        file_put_contents($d . '/VERSION', '6.6.6');
        $this->assertSame('5.5.5', $v->get());
        $this->assertSame($first, $v->get());
    }

    public function testToStringMatchesGet(): void
    {
        $d = $this->tmpDir();
        file_put_contents($d . '/VERSION', '7.7.7');
        $v = new AppVersion($d);
        $this->assertSame('7.7.7', (string) $v);
    }

    public function testComputeAlwaysProducesMajorYymmCommitsShape(): void
    {
        // compute() ignores any VERSION file and derives the string live.
        // In a throwaway dir with no git repo, commit count resolves to 0.
        $this->assertMatchesRegularExpression('/^3\.\d{4}\.\d+$/', (new AppVersion($this->tmpDir()))->compute());
    }
}
