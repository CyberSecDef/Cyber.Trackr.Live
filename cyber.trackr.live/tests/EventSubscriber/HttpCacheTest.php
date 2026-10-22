<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ApiRateLimitSubscriber;
use App\EventSubscriber\HttpCacheSubscriber;
use App\EventSubscriber\RouteRateLimitSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Review P10/P11: pages and the API carry an ETag derived from the data
 * version, and a matching If-None-Match is answered with an empty 304
 * BEFORE the controller runs.
 */
class HttpCacheTest extends WebTestCase
{
    public function testPagesAreRevalidatedByETag(): void
    {
        $client = static::createClient();
        $client->request('GET', '/stig');
        $this->assertResponseIsSuccessful();
        $headers = $client->getResponse()->headers;
        $etag = (string) $headers->get('ETag');

        $this->assertMatchesRegularExpression('/^"[0-9a-f]{40}"$/', $etag);
        $this->assertTrue($headers->hasCacheControlDirective('public'));
        $this->assertTrue($headers->hasCacheControlDirective('no-cache'), 'always revalidated, never served stale');
        // Without our own Expires, DreamHost's mod_expires appends a second
        // Cache-Control (max-age=600 for HTML) next to ours.
        $this->assertTrue($headers->has('Expires'));

        $client->request('GET', '/stig', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        $this->assertResponseStatusCodeSame(304);
        $this->assertSame('', $client->getResponse()->getContent());
        $this->assertSame($etag, $client->getResponse()->headers->get('ETag'));
        $this->assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-cache'));
    }

    public function testDifferentUrlsGetDifferentETags(): void
    {
        $client = static::createClient();
        $client->request('GET', '/stig');
        $stig = $client->getResponse()->headers->get('ETag');
        $client->request('GET', '/scap');
        $this->assertNotSame($stig, $client->getResponse()->headers->get('ETag'));

        $client->request('GET', '/scap', [], [], ['HTTP_IF_NONE_MATCH' => $stig]);
        $this->assertResponseStatusCodeSame(200);
    }

    /** Apache's mod_deflate turns "abc" into "abc-gzip" and browsers echo that back. */
    public function testGzipSuffixedAndWeakETagsStillMatch(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cci');
        $etag = (string) $client->getResponse()->headers->get('ETag');
        $gzip = substr($etag, 0, -1) . '-gzip"';

        foreach ([$gzip, 'W/' . $gzip, '"other", ' . $gzip] as $sent) {
            $client->request('GET', '/cci', [], [], ['HTTP_IF_NONE_MATCH' => $sent]);
            $this->assertResponseStatusCodeSame(304, "If-None-Match: $sent");
        }
    }

    /** The 304 is decided before the controller: /cci/data never rebuilds its rows. */
    public function testMatchingRequestSkipsTheController(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cci/data');
        $this->assertResponseIsSuccessful();
        $etag = (string) $client->getResponse()->headers->get('ETag');
        $this->assertNotSame('', $etag);

        $cache = static::getContainer()->get('cache.app');
        $cache->deleteItem('cci_data_json');

        $client->request('GET', '/cci/data', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        $this->assertResponseStatusCodeSame(304);
        $this->assertFalse(static::getContainer()->get('cache.app')->getItem('cci_data_json')->isHit(),
            'the controller did not run');
    }

    public function testCciDataIsServedFromItsCacheUnchanged(): void
    {
        $client = static::createClient();
        static::getContainer()->get('cache.app')->deleteItem('cci_data_json');
        $client->request('GET', '/cci/data');
        $built = (string) $client->getResponse()->getContent();

        $client->request('GET', '/cci/data');
        $this->assertResponseHeaderSame('Content-Type', 'application/json');
        $this->assertSame($built, (string) $client->getResponse()->getContent());
        $this->assertTrue(static::getContainer()->get('cache.app')->getItem('cci_data_json')->isHit());
        $this->assertArrayHasKey('controls', json_decode($built, true));
    }

    public function testApiKeepsItsHourAndGetsAnExpires(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/rmf/5');
        $headers = $client->getResponse()->headers;
        $this->assertSame('3600', (string) $headers->getCacheControlDirective('max-age'));
        $this->assertTrue($headers->hasCacheControlDirective('public'));
        $this->assertTrue($headers->has('Expires'));

        $client->request('GET', '/api/rmf/5', [], [], ['HTTP_IF_NONE_MATCH' => $headers->get('ETag')]);
        $this->assertResponseStatusCodeSame(304);
        $this->assertSame('3600', (string) $client->getResponse()->headers->getCacheControlDirective('max-age'));
    }

    /** no-store answers carry an Expires too, or mod_expires adds max-age=172800 to them. */
    public function testUncacheableApiAnswersAreNoStore(): void
    {
        $client = static::createClient();
        foreach (['/api/stig/tip', '/api/cci/CCI-999999'] as $url) {
            $client->request('GET', $url);
            $headers = $client->getResponse()->headers;
            $this->assertTrue($headers->hasCacheControlDirective('no-store'), $url);
            $this->assertFalse($headers->has('ETag'), $url);
            $this->assertSame('Thu, 01 Jan 1970 00:00:00 GMT', $headers->get('Expires'), $url);
        }
    }

    /** ?m= asks for an AI mitigation, which is not part of the data files. */
    public function testMitigationRequestsAreRevalidatedByContent(): void
    {
        $client = static::createClient();
        $url = '/api/stig/Windows_11/2/9/V-253254?m=not-the-marker';
        $client->request('GET', $url);
        $this->assertResponseIsSuccessful();
        $etag = (string) $client->getResponse()->headers->get('ETag');
        $this->assertSame('"' . sha1((string) $client->getResponse()->getContent()) . '"', $etag);
    }

    /** Downloads keep Symfony's defaults: skeleton.cklb mints fresh UUIDs each time. */
    public function testDownloadsAndErrorsAreLeftAlone(): void
    {
        $client = static::createClient();
        $client->request('GET', '/stig/Windows_11/2/9/skeleton.cklb');
        $this->assertResponseIsSuccessful();
        $this->assertFalse($client->getResponse()->headers->has('ETag'));

        $client->request('GET', '/stig/No_Such_Title/1/1');
        $this->assertResponseStatusCodeSame(404);
        $this->assertFalse($client->getResponse()->headers->has('ETag'));
    }

    public function testNonGetRequestsAreNotShortCircuited(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cci');
        $etag = (string) $client->getResponse()->headers->get('ETag');
        $client->request('POST', '/cci', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        $this->assertNotSame(304, $client->getResponse()->getStatusCode());
    }

    /** Runs after routing and both rate limiters, so a throttled client still gets 429. */
    public function testEarlyCheckRunsAfterRoutingAndRateLimits(): void
    {
        static::bootKernel();
        $order = [];
        foreach (static::getContainer()->get('event_dispatcher')->getListeners(KernelEvents::REQUEST) as $listener) {
            $object = is_array($listener) ? $listener[0] : $listener;
            if ($object instanceof \Closure) {
                $object = (new \ReflectionFunction($object))->getClosureThis();
            }
            $order[] = is_object($object) ? get_class($object) : '';
        }
        $pos = fn(string $class) => array_search($class, $order, true);
        $this->assertNotFalse($pos(HttpCacheSubscriber::class));
        foreach ([RouterListener::class, ApiRateLimitSubscriber::class, RouteRateLimitSubscriber::class] as $before) {
            $this->assertNotFalse($pos($before), $before);
            $this->assertLessThan($pos(HttpCacheSubscriber::class), $pos($before), "$before runs first");
        }
    }
}
