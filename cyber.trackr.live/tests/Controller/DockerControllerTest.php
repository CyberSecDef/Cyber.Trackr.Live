<?php

namespace App\Tests\Controller;

use App\Controller\DockerController;
use App\Service\DockerImageLocator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;

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
     * P3: the bundle is handed to the web server via a redirect into
     * public/downloads/docker (a symlink to dist/) instead of streaming
     * through PHP, which mod_fcgid on prod cut off mid-transfer.
     */
    public function testDownloadRedirectsToTheStaticFile(): void
    {
        $dir = sys_get_temp_dir() . '/dockerctl_' . uniqid('', true);
        mkdir($dir . '/dist', 0777, true);
        file_put_contents($dir . '/dist/cyber-trackr-2026-06.tar.gz', 'fake-gzip-bytes');

        try {
            $response = (new DockerController())->download(new DockerImageLocator($dir));

            $this->assertInstanceOf(RedirectResponse::class, $response);
            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/downloads/docker/cyber-trackr-2026-06.tar.gz', $response->getTargetUrl());
        } finally {
            @unlink($dir . '/dist/cyber-trackr-2026-06.tar.gz');
            @rmdir($dir . '/dist');
            @rmdir($dir);
        }
    }

    public function testDownloadSymlinksPointAtTheServedDirectories(): void
    {
        $public = dirname(__DIR__, 2) . '/public/downloads';
        $this->assertTrue(is_link("$public/docker"));
        $this->assertSame('../../dist', readlink("$public/docker"));
        $this->assertTrue(is_link("$public/zips"));
        $this->assertSame('../../resources/data/zips', readlink("$public/zips"));
        $this->assertSame(realpath(dirname(__DIR__, 2) . '/resources/data/zips'), realpath("$public/zips"));
    }
}
