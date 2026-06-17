<?php

namespace App\Tests\EventSubscriber;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP caching on the public JSON API (App\EventSubscriber\ApiCacheSubscriber).
 */
class ApiCacheTest extends WebTestCase
{
    /**
     * A successful API response advertises itself as publicly cacheable, with a
     * bounded freshness window and an ETag for revalidation.
     */
    public function testApiResponsesAreCacheable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api');
        $this->assertResponseIsSuccessful();

        $cacheControl = $client->getResponse()->headers->get('Cache-Control');
        $this->assertStringContainsString('public', (string) $cacheControl);
        $this->assertStringContainsString('max-age=3600', (string) $cacheControl);
        $this->assertNotEmpty(
            $client->getResponse()->headers->get('ETag'),
            'cacheable API responses should carry an ETag'
        );
    }

    /**
     * A conditional request whose If-None-Match matches the ETag gets an empty
     * 304 Not Modified instead of the full body.
     */
    public function testConditionalRequestReturns304(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/rmf/5');
        $this->assertResponseIsSuccessful();
        $etag = $client->getResponse()->headers->get('ETag');
        $this->assertNotEmpty($etag, 'first response should set an ETag');

        $client->request('GET', '/api/rmf/5', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        $this->assertSame(304, $client->getResponse()->getStatusCode());
        $this->assertEmpty($client->getResponse()->getContent(), '304 must have no body');
    }

    /**
     * The randomised "tip" endpoint must never be cached — a cached copy would
     * freeze the random selection for everyone behind a shared cache.
     */
    public function testRandomTipEndpointIsNotCached(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stig/tip');

        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringNotContainsString('max-age=3600', $cacheControl);
    }
}
