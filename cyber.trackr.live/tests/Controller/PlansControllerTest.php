<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PlansControllerTest extends WebTestCase
{
    public function testIndexRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/plans');
        $this->assertResponseIsSuccessful();
    }

    public function testWizardRendersForKnownFamily(): void
    {
        $client = static::createClient();
        $client->request('GET', '/plans/ac');
        $this->assertResponseIsSuccessful();
    }

    public function testSchemaReturnsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/plans/ac/schema.json');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
    }

    public function testControlsForBaselineRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/plans/ac/controls/nist-low');
        $this->assertResponseIsSuccessful();
    }

    public function testUnknownFamilyIs404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/plans/zz');
        $this->assertResponseStatusCodeSame(404);
    }
}
