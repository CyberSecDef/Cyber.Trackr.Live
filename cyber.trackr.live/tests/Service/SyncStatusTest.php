<?php

namespace App\Tests\Service;

use App\Service\SyncStatus;
use PHPUnit\Framework\TestCase;

class SyncStatusTest extends TestCase
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

    public function testEverythingNullWhenNothingPresent(): void
    {
        $status = new SyncStatus($this->tmpDir());
        $this->assertNull($status->getDisa());
        $this->assertNull($status->getNist());
        $this->assertNull($status->getCisa());
        $this->assertFalse($status->isAvailable());
    }

    public function testGetDisaReadsTimestamp(): void
    {
        $dir = $this->tmpDir();
        $this->writeSyncStatus($dir, ['disa' => '2026-05-01T00:00:00Z']);
        $status = new SyncStatus($dir);

        $this->assertTrue($status->isAvailable());
        $this->assertInstanceOf(\DateTimeImmutable::class, $status->getDisa());
        $this->assertSame('2026-05-01', $status->getDisa()->format('Y-m-d'));
    }

    public function testInvalidDisaDateIsNull(): void
    {
        $dir = $this->tmpDir();
        $this->writeSyncStatus($dir, ['disa' => 'not-a-date']);
        $this->assertNull((new SyncStatus($dir))->getDisa());
    }

    public function testGetCisaDerivesFromKevMtime(): void
    {
        $dir = $this->tmpDir();
        $kevDir = $dir . '/resources/data/kev';
        mkdir($kevDir, 0777, true);
        file_put_contents($kevDir . '/known_exploited_vulnerabilities.json', '{"vulnerabilities":[]}');

        $this->assertInstanceOf(\DateTimeImmutable::class, (new SyncStatus($dir))->getCisa());
    }

    public function testGetNistDerivesFromNewestOverlayMtime(): void
    {
        $dir = $this->tmpDir();
        $ovDir = $dir . '/resources/data/overlays';
        mkdir($ovDir, 0777, true);
        file_put_contents($ovDir . '/NIST_LOW-baseline_profile.json', '{}');

        $this->assertInstanceOf(\DateTimeImmutable::class, (new SyncStatus($dir))->getNist());
    }

    public function testMarkDisaSyncedNowStampsAndPreservesOtherKeys(): void
    {
        $dir = $this->tmpDir();
        $this->writeSyncStatus($dir, ['nist' => '2026-01-01', '_comment' => 'keep me']);
        $status = new SyncStatus($dir);

        $this->assertTrue($status->markDisaSyncedNow());
        // Disa now resolves...
        $this->assertInstanceOf(\DateTimeImmutable::class, $status->getDisa());
        // ...and the other keys survived the atomic rewrite.
        $written = json_decode((string) file_get_contents($dir . '/resources/data/sync_status.json'), true);
        $this->assertSame('2026-01-01', $written['nist']);
        $this->assertSame('keep me', $written['_comment']);
        $this->assertArrayHasKey('disa', $written);
    }

    // ---- helpers ------------------------------------------------------------

    private function tmpDir(): string
    {
        $d = sys_get_temp_dir() . '/sstest_' . uniqid('', true);
        mkdir($d . '/resources/data', 0777, true);
        $this->tmpDirs[] = $d;
        return $d;
    }

    private function writeSyncStatus(string $projectDir, array $data): void
    {
        file_put_contents($projectDir . '/resources/data/sync_status.json', json_encode($data));
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
