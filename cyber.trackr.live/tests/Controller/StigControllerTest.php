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

    /** @return iterable<string, array{string, string, string}> */
    public static function ruleControlLinks(): iterable
    {
        // Unchanged mapping: CCI-000054 -> AC-10.
        yield 'existing link' => ['/stig/' . self::TITLE . '/4/8', 'V-69239', 'AC-10'];
        // CCI-001321: Rev 3 SI-13 (2) was renumbered; Rev 5 maps it to SI-7 (16).
        yield 'corrected to Rev 5' => ['/stig/Riverbed_NetIM_OS/1/1', 'V-275600', 'SI-7'];
        // CCI-004066 is only in the 2024 CCI list; it used to render no link.
        yield 'newly linked' => ['/stig/AAA_Services_Security_Requirements_Guide/2/2', 'V-204664', 'IA-5'];
    }

    // P1: the RMF Control column comes from CciControlMap, not per-rule XPath.
    #[\PHPUnit\Framework\Attributes\DataProvider('ruleControlLinks')]
    public function testStigViewLinksRuleToItsRev5Control(string $url, string $vuln, string $control): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $url);
        $this->assertResponseIsSuccessful();

        $link = $crawler->filter('a[name="req_' . $vuln . '"] + div.title-details a[href^="/rmf/5#"]');
        $this->assertCount(1, $link, "$vuln should show an RMF control link");
        $this->assertSame('/rmf/5#rmf_' . $control, $link->attr('href'));
        $this->assertSame($control, trim($link->text()));
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
