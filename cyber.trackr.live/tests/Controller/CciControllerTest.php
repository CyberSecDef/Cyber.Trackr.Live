<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CciControllerTest extends WebTestCase
{
    public function testCciPageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cci');
        $this->assertResponseIsSuccessful();
    }

    public function testCciDataIsRevisionAware(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cci/data');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        // String checks (no full json_decode) keep this light despite the large payload.
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('"controls":', $content);  // deduped control map
        $this->assertStringContainsString('"rev":"4"', $content);    // Rev-4-only (dropped) CCIs
        $this->assertStringContainsString('"deprecated":true', $content);
    }
}
