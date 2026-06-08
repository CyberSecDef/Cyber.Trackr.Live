<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HomeControllerTest extends WebTestCase
{
    public function testHomeRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
    }

    #[DataProvider('staticPages')]
    public function testStaticPageRenders(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);
        $this->assertResponseIsSuccessful();
    }

    public static function staticPages(): array
    {
        return [
            'report_generator' => ['/report_generator'],
            'contactus'        => ['/contactus'],
            'mission'          => ['/mission'],
            'ckl-viewer'       => ['/ckl-viewer'],
            'terms'            => ['/terms'],
            'changelog'        => ['/changelog'],
        ];
    }

    public function testSearchRendersWhenIndexIsBuilt(): void
    {
        // The inverted index under resources/data/search/ is generated (and
        // gitignored), so skip cleanly on a checkout that hasn't built it.
        $index = dirname(__DIR__, 2) . '/resources/data/search/postings.json';
        if (!is_file($index)) {
            $this->markTestSkipped('search index not built (resources/data/search/postings.json missing)');
        }
        $client = static::createClient();
        $client->request('GET', '/search/windows');
        $this->assertResponseIsSuccessful();
    }
}
