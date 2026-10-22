<?php

namespace App\Tests\Service;

use App\Service\AtomicFile;
use PHPUnit\Framework\TestCase;

class AtomicFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/atomic_' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @chmod($this->dir, 0755);
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        rmdir($this->dir);
    }

    /** @return list<string> every file in the dir, dotfiles included */
    private function files(): array
    {
        return array_values(array_diff(scandir($this->dir), ['.', '..']));
    }

    public function testWritesContentWith0644AndLeavesNoTempFile(): void
    {
        $path = $this->dir . '/stig_toc.json';
        $old = umask(0077); // a restrictive umask must not leak into the result
        try {
            $this->assertTrue(AtomicFile::write($path, '{"a":1}'));
        } finally {
            umask($old);
        }

        $this->assertSame('{"a":1}', file_get_contents($path));
        $this->assertSame('644', substr(sprintf('%o', fileperms($path)), -3));
        $this->assertSame(['stig_toc.json'], $this->files());
    }

    public function testReplacesAnExistingFile(): void
    {
        $path = $this->dir . '/stig_toc.json';
        file_put_contents($path, str_repeat('x', 100000));

        $this->assertTrue(AtomicFile::write($path, 'short'));
        $this->assertSame('short', file_get_contents($path));
        $this->assertSame(['stig_toc.json'], $this->files());
    }

    public function testFailureLeavesTheExistingFileUntouched(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores directory permissions');
        }
        $path = $this->dir . '/stig_toc.json';
        file_put_contents($path, 'original');
        chmod($this->dir, 0555); // can't create the temp file

        $this->assertFalse(AtomicFile::write($path, 'new'));
        $this->assertSame('original', file_get_contents($path));
        $this->assertSame(['stig_toc.json'], $this->files());
    }

    public function testMissingDirectoryFails(): void
    {
        $this->assertFalse(AtomicFile::write($this->dir . '/nope/stig_toc.json', 'x'));
    }
}
