<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Each release is listed once, newest first, including releases that share a
 * benchmark date (the toc's order decides those; the pages used to reverse
 * an ascending sort, which flipped them). The API keeps its public fields.
 */
class StigVersionListTest extends WebTestCase
{
    public function testVersionsAreUniqueAndNewestFirst(): void
    {
        $client = static::createClient();
        // Outlook 2007 V4R12 and V4R13 share 2014-10-03; IBM HMC lists V1R5 under two filenames.
        foreach (['/stig/Outlook_2007/4/15' => ['4|16', '4|15', '4|13', '4|12'],
                  '/stig/IBM_Hardware_Management_Console_(HMC)_STIG/1/5' => null] as $url => $expected) {
            $crawler = $client->request('GET', $url);
            if ($client->getResponse()->getStatusCode() === 404) {
                $this->markTestSkipped("$url not in the data set");
            }
            $options = array_values(array_filter(
                $crawler->filter('select#view_stig option')->each(fn($o) => (string) $o->attr('value'))));
            $this->assertSame(array_values(array_unique($options)), $options, "$url lists each release once");
            if ($expected !== null) {
                $this->assertSame($expected, array_slice($options, 0, 4), "$url newest first");
            }
        }
    }

    /** SCAP: Cisco IOS XE Router RTR V3R4 and V3R5 share a date; the old order listed V3R4 first. */
    public function testScapVersionsAreNewestFirst(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/scap/Cisco_IOS_XE_Router_RTR_STIG_SCAP_Benchmark/3/5');
        if ($client->getResponse()->getStatusCode() === 404) {
            $this->markTestSkipped('Cisco IOS XE Router RTR SCAP V3R5 not in the data set');
        }
        $options = array_values(array_filter(
            $crawler->filter('select#view_scap option')->each(fn($o) => (string) $o->attr('value'))));
        $i4 = array_search('3|4', $options, true);
        $i5 = array_search('3|5', $options, true);
        $this->assertNotFalse($i4);
        $this->assertLessThan($i4, $i5, 'V3R5 is listed above V3R4');
    }

    public function testApiListingKeepsItsPublicFields(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stig');
        $keys = [];
        foreach (json_decode((string) $client->getResponse()->getContent(), true) as $rows) {
            foreach ($rows as $row) {
                $keys += array_flip(array_keys($row));
            }
        }
        ksort($keys);
        $this->assertSame(['date', 'link', 'release', 'released', 'sev', 'version'], array_keys($keys));
    }
}
