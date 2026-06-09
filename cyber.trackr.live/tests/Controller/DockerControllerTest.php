<?php

namespace App\Tests\Controller;

use App\Controller\DockerController;
use App\Service\DockerImageLocator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DockerControllerTest extends WebTestCase
{
    /** The instructions page + footer link only exist when a bundle is on disk. */
    private function bundlePresent(): bool
    {
        return (glob(dirname(__DIR__, 2) . '/dist/*.tar.gz') ?: []) !== [];
    }

    public function testInstructionsPageRendersWhenBundlePresent(): void
    {
        if (!$this->bundlePresent()) {
            $this->markTestSkipped('no dist/*.tar.gz bundle present');
        }
        $client = static::createClient();
        $client->request('GET', '/docker-image');

        $this->assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('docker load', $body);
        $this->assertStringContainsString('docker compose up -d', $body);
        $this->assertStringContainsString('docker run -d', $body);
    }

    public function testFooterLinkPresentWhenBundlePresent(): void
    {
        if (!$this->bundlePresent()) {
            $this->markTestSkipped('no dist/*.tar.gz bundle present');
        }
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('/docker-image', (string) $client->getResponse()->getContent());
    }

    /**
     * Download headers are checked by invoking the controller directly against a
     * tiny temp bundle — driving the real (multi-GB) file through the test client
     * would materialize it into memory.
     */
    public function testDownloadReturnsGzipAttachment(): void
    {
        $dir = sys_get_temp_dir() . '/dockerctl_' . uniqid('', true);
        mkdir($dir . '/dist', 0777, true);
        file_put_contents($dir . '/dist/cyber-trackr-2026-06.tar.gz', 'fake-gzip-bytes');

        try {
            $response = (new DockerController())->download(new DockerImageLocator($dir));

            $this->assertInstanceOf(BinaryFileResponse::class, $response);
            $this->assertSame('application/gzip', $response->headers->get('Content-Type'));
            $this->assertStringContainsString(
                'cyber-trackr-2026-06.tar.gz',
                (string) $response->headers->get('Content-Disposition')
            );
            $this->assertSame('cyber-trackr-2026-06.tar.gz', $response->getFile()->getFilename());
        } finally {
            @unlink($dir . '/dist/cyber-trackr-2026-06.tar.gz');
            @rmdir($dir . '/dist');
            @rmdir($dir);
        }
    }
}
