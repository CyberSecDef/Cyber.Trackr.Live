<?php

namespace App\Tests\Service;

use App\Service\CachedFileCount;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** Review P15: the home page counts are remembered until their file changes. */
class CachedFileCountTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/cfc_' . uniqid('', true) . '.xml';
        file_put_contents($this->file, '<a><cci_item id="1"/><cci_item id="2"/></a>');
        touch($this->file, 1_700_000_000);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testCountsAndRemembers(): void
    {
        $counter = new CachedFileCount(new ArrayAdapter());
        $this->assertSame(2, $counter->count($this->file, ['<cci_item ']));

        // Same size and mtime: the remembered count is used, the file is not re-read.
        file_put_contents($this->file, '<a><cci_item id="1"/><xxx_xxxx id="2"/></a>');
        touch($this->file, 1_700_000_000);
        clearstatcache();
        $this->assertSame(2, $counter->count($this->file, ['<cci_item ']));
    }

    public function testAChangedFileIsCountedAgain(): void
    {
        $counter = new CachedFileCount(new ArrayAdapter());
        $this->assertSame(2, $counter->count($this->file, ['<cci_item ']));

        file_put_contents($this->file, '<a><cci_item id="1"/><cci_item id="2"/><cci_item id="3"/></a>');
        clearstatcache();
        $this->assertSame(3, $counter->count($this->file, ['<cci_item ']));
    }

    public function testNeedlesAreSummedAndKeyedSeparately(): void
    {
        $counter = new CachedFileCount(new ArrayAdapter());
        file_put_contents($this->file, '<c:control id="1"/><c:control><c:control x/>');
        $this->assertSame(3, $counter->count($this->file, ['<c:control ', '<c:control>']));
        $this->assertSame(1, $counter->count($this->file, ['<c:control>']));
    }

    public function testMissingFileIsZero(): void
    {
        $this->assertSame(0, (new CachedFileCount(new ArrayAdapter()))->count('/nonexistent/file.xml', ['x']));
    }
}
