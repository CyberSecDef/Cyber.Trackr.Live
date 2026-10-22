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

    // S7/P7: /stig and /scap used to parse any XML missing from the toc and
    // rewrite the toc mid-request, racing the cron's rebuild. Only the
    // rebuild commands write it now.
    public function testIndexPagesNeverRewriteTheToc(): void
    {
        $client = static::createClient();
        $dataDir = static::getContainer()->getParameter('kernel.project_dir') . '/resources/data';
        $stigToc = $dataDir . '/stig_toc.json';
        $scapToc = $dataDir . '/scap_toc.json';
        $before = [$stigToc => file_get_contents($stigToc), $scapToc => file_get_contents($scapToc)];

        // An XML the toc doesn't know about: a copy of a real STIG under a new name.
        $src = glob($dataDir . '/stig/U_ASD_STIG_V4R8_Manual-xccdf.xml')[0];
        $stray = $dataDir . '/stig/U_Zz_S7_Test_STIG_V1R1_Manual-xccdf.xml';
        copy($src, $stray);
        try {
            $client->request('GET', '/stig');
            $this->assertResponseIsSuccessful();
            $client->request('GET', '/scap');
            $this->assertResponseIsSuccessful();

            clearstatcache();
            $this->assertSame($before[$stigToc], file_get_contents($stigToc), '/stig must not rewrite stig_toc.json');
            $this->assertSame($before[$scapToc], file_get_contents($scapToc), '/scap must not rewrite scap_toc.json');
        } finally {
            @unlink($stray);
            foreach ($before as $path => $contents) {
                if (file_get_contents($path) !== $contents) {
                    file_put_contents($path, $contents); // undo, so a failure can't leave the dev toc changed
                }
            }
        }
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

        // P9: group lookups now come from maps built in the controller. DISA
        // renumbered every rule between these releases, so all 286 V6R4 rules
        // show, plus all 289 V4R8 rules in the removed section (the path that
        // used to be O(n^2)).
        $html = (string) $client->getResponse()->getContent();
        $this->assertSame(286 + 289, substr_count($html, 'class="compare-container'));
        $this->assertStringContainsString('V-69239', $html);
    }

    // Guard from commit 8710c31: valid title, unknown version/release -> 404.
    public function testStigViewUnknownVersionIs404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/stig/' . self::TITLE . '/99/99');
        $this->assertResponseStatusCodeSame(404);
    }

    // S19: values printed into JS strings must be JS- and URL-escaped. The one
    // title with "&" used to come out as "&amp;" inside the script.
    public function testTitleWithAmpersandIsEscapedForJavaScript(): void
    {
        $title = 'Intune_MDM_Service_Desktop_&_Mobile';
        $client = static::createClient();
        $client->request('GET', '/stig/' . rawurlencode($title) . '/1/1');
        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // Decode the JS string literals the way a browser would (JSON shares
        // JS's \uXXXX escapes): e('js') emits \u0025, which evaluates to "%".
        preg_match_all('/\\.replace\\(\'XXXX\',("[^"]*")\\)/', $html, $replace);
        preg_match('/\\$\\.getJSON\\( ("\\/api\\/stig\\/[^"]*")/', $html, $api);
        $this->assertCount(2, $replace[1], 'view + compare URLs');
        foreach ($replace[1] as $literal) {
            $this->assertSame('Intune_MDM_Service_Desktop_%26_Mobile', json_decode($literal));
            $this->assertSame($title, rawurldecode(json_decode($literal)));
        }
        $this->assertSame('/api/stig/Intune_MDM_Service_Desktop_%26_Mobile/1/1/', json_decode($api[1]));
    }

    // P3: companion ZIPs (up to 158 MB) are served by the web server.
    public function testCompanionZipRedirectsToTheStaticFile(): void
    {
        $client = static::createClient();
        $data = dirname(__DIR__, 2) . '/resources/data';
        $index = json_decode((string) @file_get_contents("$data/companion_zip_index.json"), true) ?: [];
        $found = null;
        foreach (json_decode((string) file_get_contents("$data/stig_toc.json"), true) as $title => $versions) {
            foreach ($versions as $v) {
                if (isset($index[$v['filename']])) { $found = [$title, $v['version'], $v['release'], $index[$v['filename']]]; break 2; }
            }
        }
        if ($found === null) {
            $this->markTestSkipped('companion_zip_index.json has no entries; run app:companion-zip:rebuild-index');
        }
        [$title, $version, $release, $zip] = $found;
        $path = $data . '/zips/' . $zip;

        $client->request('GET', '/stig/' . rawurlencode($title) . "/$version/$release/companion.zip");
        $this->assertResponseRedirects('/downloads/zips/' . rawurlencode(basename($path)), 302);
        $this->assertFileExists(dirname(__DIR__, 2) . '/public/downloads/zips/' . basename($path));
    }
}
