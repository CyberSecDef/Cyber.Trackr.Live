<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Firefox's SCAP benchmarks are listed per platform; links to the two titles
 * that held both platforms redirect to the Linux one (what they showed).
 */
class ScapPlatformSplitTest extends WebTestCase
{
    private const LINUX = 'Mozilla_Firefox_for_Linux_STIG_SCAP_Benchmark';
    private const WINDOWS = 'Mozilla_Firefox_for_Windows_STIG_SCAP_Benchmark';

    public function testBothPlatformsOfAReleaseAreReachable(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/resources/data/scap/U_MOZ_Firefox_Windows_V6R3_STIG_SCAP_1-2_Benchmark.xml')) {
            $this->markTestSkipped('Firefox SCAP V6R3 not in the data set');
        }
        $client = static::createClient();
        $client->request('GET', '/scap/' . self::WINDOWS . '/6/3');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Windows', (string) $client->getCrawler()->filter('title')->text());

        $client->request('GET', '/scap/' . self::LINUX . '/6/3');
        $this->assertResponseIsSuccessful();
    }

    public function testRetiredTitlesRedirect(): void
    {
        $client = static::createClient();
        foreach ([
            '/scap/Mozilla_Firefox/6/2' => '/scap/' . self::LINUX . '/6/2',
            '/scap/Mozilla_FireFox/1/3/download' => '/scap/' . self::LINUX . '/1/3/download',
            '/api/scap/Mozilla_Firefox/6/2' => '/api/scap/' . self::LINUX . '/6/2',
        ] as $old => $new) {
            $client->request('GET', $old);
            if ($client->getResponse()->getStatusCode() === 404 && !is_file(dirname(__DIR__, 2) . '/resources/data/scap_toc.json')) {
                $this->markTestSkipped('no SCAP toc');
            }
            $this->assertResponseStatusCodeSame(301, $old);
            $this->assertSame($new, $client->getResponse()->headers->get('Location'), $old);
        }

        $client->request('GET', '/scap/Mozilla_Firefox/9/9');
        $this->assertResponseStatusCodeSame(404, 'a release no platform has');
    }
}
