<?php

namespace App\Tests\Service;

use App\Service\AtomicFile;
use App\Service\DataVersion;
use PHPUnit\Framework\TestCase;

/**
 * The cache version must move whenever any data refresh or deploy lands,
 * otherwise a revalidating browser is told its stale copy is current.
 */
class DataVersionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dvtest_' . uniqid('', true);
        mkdir($this->dir . '/resources/data/stig', 0777, true);
        mkdir($this->dir . '/cache', 0777, true);
        file_put_contents($this->dir . '/cache/Container.php', '<?php');
        file_put_contents($this->dir . '/resources/data/stig_toc.json', '{}');
        file_put_contents($this->dir . '/resources/data/stig/A.xml', '<a/>');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function version(): string
    {
        clearstatcache();
        return (new DataVersion($this->dir, $this->dir . '/cache', 'Container'))->data();
    }

    public function testStableWhileNothingChanges(): void
    {
        $this->assertSame($this->version(), $this->version());
    }

    public function testAtomicReplaceOfATopLevelFileChangesIt(): void
    {
        $before = $this->version();
        AtomicFile::write($this->dir . '/resources/data/stig_toc.json', '{}'); // same bytes, same second
        $this->assertNotSame($before, $this->version());
    }

    public function testANewFileRenamedIntoASubdirectoryChangesIt(): void
    {
        // Pin the directory's mtime in the past so the rename below is the
        // only thing that can move it (PHP's stat() has one-second mtimes).
        touch($this->dir . '/resources/data/stig', time() - 100);
        $before = $this->version();
        $tmp = $this->dir . '/resources/data/stig/.B.xml.tmp';
        file_put_contents($tmp, '<b/>');
        rename($tmp, $this->dir . '/resources/data/stig/B.xml');
        $this->assertNotSame($before, $this->version());
    }

    public function testInFlightTempFilesAreIgnored(): void
    {
        $before = $this->version();
        $dirMtime = filemtime($this->dir . '/resources/data');
        file_put_contents($this->dir . '/resources/data/.stig_toc.json.abc.tmp', 'partial');
        touch($this->dir . '/resources/data', $dirMtime);
        $this->assertSame($before, $this->version());
    }

    public function testARebuiltContainerChangesIt(): void
    {
        $before = $this->version();
        touch($this->dir . '/cache/Container.php', time() + 5);
        $this->assertNotSame($before, $this->version());
    }

    public function testPageVersionAddsTheDate(): void
    {
        $v = new DataVersion($this->dir, $this->dir . '/cache', 'Container');
        $this->assertSame(sha1($v->data() . "\n" . date('Y-m-d')), $v->page());
    }
}
