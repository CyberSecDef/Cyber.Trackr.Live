<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StigControllerTest extends WebTestCase
{
    private const TITLE = 'Application_Security_and_Development';

    public function testStigIndexRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/stig');
        $this->assertResponseIsSuccessful();
    }

    public function testStigViewRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/stig/' . self::TITLE . '/4/8');
        $this->assertResponseIsSuccessful();
    }

    public function testStigCompareRenders(): void
    {
        $client = static::createClient();
        // Compare V4R8 against V6R4 — both present in the toc for this title.
        $client->request('GET', '/stig/' . self::TITLE . '/4/8/6/4');
        $this->assertResponseIsSuccessful();
    }

    // Guard from commit 8710c31: valid title, unknown version/release -> 404.
    public function testStigViewUnknownVersionIs404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/stig/' . self::TITLE . '/99/99');
        $this->assertResponseStatusCodeSame(404);
    }
}
