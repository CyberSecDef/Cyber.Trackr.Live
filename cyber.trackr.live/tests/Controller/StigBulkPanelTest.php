<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /stig used to embed the whole bulk-download table (4,031 rows, 5.7 MB)
 * inside its collapsed panel; the panel now fetches it on first open.
 */
class StigBulkPanelTest extends WebTestCase
{
    public function testStigPageNoLongerCarriesTheTable(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/stig');
        $this->assertResponseIsSuccessful();

        $this->assertCount(0, $crawler->filter('[data-bulk-root], [data-bulk-table]'));
        $slot = $crawler->filter('[data-bulk-panel] [data-bulk-slot]');
        $this->assertCount(1, $slot);
        $this->assertSame('/stig/bulk/table', $slot->attr('data-src'));
        $this->assertLessThan(3_000_000, strlen((string) $client->getResponse()->getContent()), 'was ~7.9 MB');
    }

    public function testFragmentIsTheTableTheBulkPageShows(): void
    {
        $client = static::createClient();
        $page = $client->request('GET', '/stig/bulk');
        $rows = $page->filter('[data-bulk-table] tbody tr')->count();
        $this->assertGreaterThan(0, $rows);

        $fragment = $client->request('GET', '/stig/bulk/table');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringNotContainsString('<html', $html, 'a fragment, not a page');
        $this->assertCount(1, $fragment->filter('[data-bulk-root]'));
        $this->assertSame($rows, $fragment->filter('[data-bulk-table] tbody tr')->count());
        $this->assertNotEmpty($client->getResponse()->headers->get('ETag'), 'cached like any page (P10)');
    }
}
