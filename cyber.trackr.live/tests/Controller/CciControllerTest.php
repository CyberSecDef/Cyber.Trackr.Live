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

    public function testAssessmentProcedurePrefers80053AReference(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cci/data');
        $content = (string) $client->getResponse()->getContent();

        // CCI-001428: full 800-53A (version 1) reference, sourced from version "1".
        $this->assertStringContainsString('"assessment":["AC-16 (5).1 (iii)"]', $content);
        $this->assertStringContainsString('"assessment_src":"1"', $content);
        // CCIs with no 800-53A reference fall back to the control-ref rev (5 -> 4 -> 3).
        $this->assertStringContainsString('"assessment_src":"5"', $content);
    }
}
