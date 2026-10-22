<?php

namespace App\Tests\Controller;

use App\Service\DataVersion;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Review P12: a GET used to write the missing *.digest.json next to the STIG
 * XML. That bumped the stig/ directory mtime, and with it DataVersion, so
 * every page's ETag changed whenever someone first opened a new release.
 * Sidecars now come only from app:stig:rebuild-digests.
 */
class StigDigestReadOnlyTest extends WebTestCase
{
    private const STIG = 'resources/data/stig';
    private const SIDECAR = 'U_MS_Windows_11_STIG_V2R9_Manual-xccdf.xml.digest.json';

    public function testViewAndFeedWriteNothingAndKeepTheDataVersion(): void
    {
        $dir = dirname(__DIR__, 2) . '/' . self::STIG;
        if (!is_file("$dir/U_MS_Windows_11_STIG_V2R9_Manual-xccdf.xml")) {
            $this->markTestSkipped('Windows 11 V2R9 STIG not in the data set');
        }
        @unlink("$dir/" . self::SIDECAR); // derived; the rebuild command recreates it
        touch($dir, time() - 100);
        clearstatcache();

        $client = static::createClient();
        $version = static::getContainer()->get(DataVersion::class)->data();
        $files = scandir($dir);

        $crawler = $client->request('GET', '/stig/Windows_11/2/9');
        $this->assertResponseIsSuccessful();
        $digest = $crawler->filter('.stig-digest');
        $this->assertGreaterThan(0, $digest->count(), 'the "changes since" digest is still shown');
        $this->assertStringContainsString('vs. V2R7', preg_replace('/\s+/', ' ', $digest->text()));
        $client->request('GET', '/stig/feed.atom');
        $this->assertResponseIsSuccessful();

        clearstatcache();
        $this->assertFileDoesNotExist("$dir/" . self::SIDECAR);
        $this->assertSame($files, scandir($dir), 'no file was added to stig/');
        $this->assertSame($version, (new DataVersion(
            dirname(__DIR__, 2),
            static::getContainer()->getParameter('kernel.cache_dir'),
            static::getContainer()->getParameter('kernel.container_class'),
        ))->data(), 'DataVersion, and so every ETag, is unchanged');
    }
}
