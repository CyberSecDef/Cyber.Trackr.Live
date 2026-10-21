<?php

namespace App\Tests\Controller;

use App\Service\BulkDownloadIndex;
use App\Service\BulkDownloadPlanner;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Review S5: /stig/bulk-download/chunk ignored CHUNK_LIMIT_BYTES and had no
 * rate limit, so one POST could stream the whole corpus. Also covers P14
 * (companion ZIPs are STOREd, not re-deflated).
 */
class BulkDownloadTest extends WebTestCase
{
    /** @return array{0:string,1:string} [id with both XML and a small ZIP, its zip filename] */
    private function sampleId(): array
    {
        foreach (static::getContainer()->get(BulkDownloadIndex::class)->entries() as $row) {
            if (($row['xml_size'] ?? 0) > 0 && ($row['zip_size'] ?? 0) > 0 && $row['zip_size'] < 5_000_000) {
                return [$row['id'], $row['zip_filename']];
            }
        }
        $this->markTestSkipped('bulk_download_index.json has no XML+ZIP entry; run app:bulk-download:rebuild-index.');
    }

    private function postChunk(KernelBrowser $client, array $files, array $server = []): void
    {
        $client->request('POST', '/stig/bulk-download/chunk', [], [], $server + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['files' => $files, 'index' => 1, 'of' => 1]));
    }

    public function testPlanShapedChunkStreamsZipWithStoredCompanionZips(): void
    {
        $client = static::createClient();
        [$id, $zipName] = $this->sampleId();

        $this->postChunk($client, [['kind' => 'xml', 'id' => $id], ['kind' => 'zip', 'id' => $id]]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/zip');

        $tmp = tempnam(sys_get_temp_dir(), 'bulk');
        file_put_contents($tmp, $client->getInternalResponse()->getContent());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp, \ZipArchive::CHECKCONS), 'chunk must be a consistent ZIP');

        $methods = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $methods[$stat['name']] = $stat['comp_method'];
        }
        $this->assertSame(\ZipArchive::CM_STORE, $methods['zips/' . $zipName] ?? null, 'companion ZIP should be stored');
        $xmlMethods = array_filter($methods, fn ($k) => str_starts_with($k, 'stig/'), ARRAY_FILTER_USE_KEY);
        $this->assertSame([\ZipArchive::CM_DEFLATE], array_values($xmlMethods), 'XML should stay deflated');

        // The stored entry must round-trip byte-for-byte.
        $index = static::getContainer()->get(BulkDownloadIndex::class);
        $this->assertSame(md5_file($index->zipDir() . '/' . $zipName), md5($zip->getFromName('zips/' . $zipName)));
        $zip->close();
        unlink($tmp);
    }

    public function testChunkOverTheSizeCapIsRejected(): void
    {
        $client = static::createClient();
        $files = [];
        $bytes = 0;
        foreach (static::getContainer()->get(BulkDownloadIndex::class)->entries() as $row) {
            if (($row['xml_size'] ?? 0) > 0) {
                $files[] = ['kind' => 'xml', 'id' => $row['id']];
                $bytes += $row['xml_size'];
            }
            if ($bytes > BulkDownloadPlanner::CHUNK_LIMIT_BYTES * 1.1) {
                break;
            }
        }
        if ($bytes <= BulkDownloadPlanner::CHUNK_LIMIT_BYTES) {
            $this->markTestSkipped('Local corpus is smaller than one chunk.');
        }

        $this->postChunk($client, $files);
        $this->assertResponseStatusCodeSame(400);
    }

    /** @return iterable<string, array{string, string, int, callable}> */
    public static function limitedRoutes(): iterable
    {
        yield 'bulk chunk' => ['limiter.bulk_download', '203.0.113.71', 30,
            fn (self $t, KernelBrowser $c, array $s) => $t->postChunk($c, [], $s)];
        yield 'docker image' => ['limiter.docker_image', '203.0.113.72', 10,
            // HEAD: same route and limiter, but the test client doesn't buffer
            // the ~3 GB image body when a local dist/ tarball exists.
            fn (self $t, KernelBrowser $c, array $s) => $c->request('HEAD', '/docker-image/download', [], [], $s)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('limitedRoutes')]
    public function testDownloadRoutesAreRateLimitedPerIp(string $limiter, string $ip, int $limit, callable $hit): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->get($limiter)->create($ip)->reset();
        $server = ['REMOTE_ADDR' => $ip];

        // The limiter runs before the controller, so cheap 404s (empty chunk,
        // missing image) still spend the budget — no GBs streamed in tests.
        for ($i = 0; $i < $limit; $i++) {
            $hit($this, $client, $server);
            $this->assertNotSame(429, $client->getResponse()->getStatusCode(), "request $i should be under the limit");
        }

        $hit($this, $client, $server);
        $this->assertResponseStatusCodeSame(429);
        $this->assertNotEmpty($client->getResponse()->headers->get('Retry-After'));

        // A different IP is unaffected.
        $hit($this, $client, ['REMOTE_ADDR' => '203.0.113.99']);
        $this->assertNotSame(429, $client->getResponse()->getStatusCode());
        static::getContainer()->get($limiter)->create('203.0.113.99')->reset();
    }
}
