<?php

namespace App\Tests\Service;

use App\Service\DockerImageLocator;
use PHPUnit\Framework\TestCase;

class DockerImageLocatorTest extends TestCase
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

    public function testNoDistDirReturnsNull(): void
    {
        $this->assertNull((new DockerImageLocator($this->tmpDir()))->latest());
    }

    public function testEmptyDistReturnsNull(): void
    {
        $dir = $this->tmpDir();
        mkdir($dir . '/dist', 0777, true);
        $this->assertNull((new DockerImageLocator($dir))->latest());
    }

    public function testFindsBundleAndParsesTag(): void
    {
        $dir = $this->tmpDir();
        $this->writeBundle($dir, 'cyber-trackr-2026-06.tar.gz', 2048);

        $latest = (new DockerImageLocator($dir))->latest();
        $this->assertNotNull($latest);
        $this->assertSame('cyber-trackr-2026-06.tar.gz', $latest['filename']);
        $this->assertSame('2026-06', $latest['tag']);
        $this->assertSame('cyber-trackr:2026-06', $latest['image']);
        $this->assertSame(2048, $latest['bytes']);
        $this->assertSame('2.0 KB', $latest['size']);
    }

    public function testNonMatchingNameFallsBackToLatestImage(): void
    {
        $dir = $this->tmpDir();
        $this->writeBundle($dir, 'some-other-bundle.tar.gz', 10);

        $latest = (new DockerImageLocator($dir))->latest();
        $this->assertSame('latest', $latest['tag']);
        $this->assertSame('cyber-trackr:latest', $latest['image']);
    }

    public function testNewestBundleWins(): void
    {
        $dir = $this->tmpDir();
        $this->writeBundle($dir, 'cyber-trackr-2026-05.tar.gz', 10);
        $this->writeBundle($dir, 'cyber-trackr-2026-06.tar.gz', 10);
        // Make the June bundle unambiguously newer.
        touch($dir . '/dist/cyber-trackr-2026-05.tar.gz', 1_000_000);
        touch($dir . '/dist/cyber-trackr-2026-06.tar.gz', 2_000_000);

        $this->assertSame('cyber-trackr-2026-06.tar.gz', (new DockerImageLocator($dir))->latest()['filename']);
    }

    public function testBuiltAtReflectsBundleMtime(): void
    {
        $dir = $this->tmpDir();
        $this->writeBundle($dir, 'cyber-trackr-2026-06.tar.gz', 10);
        touch($dir . '/dist/cyber-trackr-2026-06.tar.gz', 1_700_000_000);

        $builtAt = (new DockerImageLocator($dir))->builtAt();
        $this->assertInstanceOf(\DateTimeImmutable::class, $builtAt);
        $this->assertSame(1_700_000_000, $builtAt->getTimestamp());
    }

    public function testBuiltAtNullWhenNoBundle(): void
    {
        $this->assertNull((new DockerImageLocator($this->tmpDir()))->builtAt());
    }

    public function testComposeYaml(): void
    {
        $dir = $this->tmpDir();
        file_put_contents($dir . '/docker-compose.yml', "services:\n  app: {}\n");
        $this->assertStringContainsString('services:', (new DockerImageLocator($dir))->composeYaml());

        // Missing file -> empty string, not an error.
        $this->assertSame('', (new DockerImageLocator($this->tmpDir()))->composeYaml());
    }

    // ---- helpers ------------------------------------------------------------

    private function tmpDir(): string
    {
        $d = sys_get_temp_dir() . '/diltest_' . uniqid('', true);
        mkdir($d, 0777, true);
        $this->tmpDirs[] = $d;
        return $d;
    }

    private function writeBundle(string $projectDir, string $name, int $bytes): void
    {
        $dist = $projectDir . '/dist';
        if (!is_dir($dist)) {
            mkdir($dist, 0777, true);
        }
        file_put_contents($dist . '/' . $name, str_repeat('x', $bytes));
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
