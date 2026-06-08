<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SitemapControllerTest extends WebTestCase
{
    public function testSitemapIsValidXml(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('xml', (string) $client->getResponse()->headers->get('Content-Type'));

        $body = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('<urlset', $body);
        $this->assertStringContainsString('<loc>', $body);
    }
}
