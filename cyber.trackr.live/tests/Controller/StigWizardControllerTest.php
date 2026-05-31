<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StigWizardControllerTest extends WebTestCase
{
    private const ASD = '/Application_Security_and_Development/6/4';

    public function testWizardPageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/stig-wizard' . self::ASD);

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('window.stigWizardConfig', $content);
        $this->assertStringContainsString('/js/stig_wizard.js', $content);
        // The schema is inlined for the client reducer.
        $this->assertStringContainsString('has_user_accounts', $content);
    }

    public function testWizardUnknownStigIs404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/stig-wizard/Google_Chrome_Current_Windows/3/1');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testViewPageShowsCtaForAsd(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/stig' . self::ASD);

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('.stig-wizard-cta')->count(), 'ASD view page must show the wizard CTA');
        $link = $crawler->filter('.stig-wizard-cta a')->attr('href');
        $this->assertSame('/stig-wizard' . self::ASD, $link);
    }

    public function testSkeletonEndpointReturnsBlankCklb(): void
    {
        $client = static::createClient();
        $client->request('GET', '/stig' . self::ASD . '/skeleton.cklb');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $rules = $data['stigs'][0]['rules'];
        $this->assertCount(286, $rules);
        foreach ($rules as $r) {
            $this->assertSame('Not_Reviewed', $r['status']);
        }
    }
}
