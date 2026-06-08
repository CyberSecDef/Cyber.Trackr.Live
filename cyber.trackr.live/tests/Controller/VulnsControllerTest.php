<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class VulnsControllerTest extends WebTestCase
{
    public function testLandingRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vulnerabilities');
        $this->assertResponseIsSuccessful();
    }

    public function testKevListRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vulnerabilities/kev');
        $this->assertResponseIsSuccessful();
    }

    public function testIavmListRendersEvenWithoutData(): void
    {
        // IAVM data is not mirrored locally; the page must still render.
        $client = static::createClient();
        $client->request('GET', '/vulnerabilities/iavm');
        $this->assertResponseIsSuccessful();
    }

    public function testKevDetailRendersForRealCve(): void
    {
        $cve = $this->firstKevCve();
        if ($cve === null) {
            $this->markTestSkipped('KEV catalog not present');
        }
        $client = static::createClient();
        $client->request('GET', '/vulnerabilities/kev/' . $cve);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($cve, (string) $client->getResponse()->getContent());
    }

    public function testCveViewRendersForRealCve(): void
    {
        $cve = $this->firstKevCve();
        if ($cve === null) {
            $this->markTestSkipped('KEV catalog not present');
        }
        $client = static::createClient();
        $client->request('GET', '/vulnerabilities/cve/' . $cve);
        $this->assertResponseIsSuccessful();
    }

    public function testUnknownKevCveIs404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vulnerabilities/kev/CVE-1999-9999');
        $this->assertResponseStatusCodeSame(404);
    }

    /** Pull a real CVE id from the committed KEV mirror, or null if absent. */
    private function firstKevCve(): ?string
    {
        $path = dirname(__DIR__, 2) . '/resources/data/kev/known_exploited_vulnerabilities.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return $data['vulnerabilities'][0]['cveID'] ?? null;
    }
}
