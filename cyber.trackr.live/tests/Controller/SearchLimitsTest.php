<?php

namespace App\Tests\Controller;

use App\Service\Search\IndexStore;
use App\Service\Search\QueryParser;
use App\Service\Search\Searcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Review S13: /search/{query} refuses over-long or over-wide queries before
 * any index work, and is rate-limited per IP.
 */
class SearchLimitsTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    /** Swap in a Searcher that counts how often the index is actually queried. */
    private function spySearcher(): Searcher
    {
        $c = static::getContainer();
        $spy = new class($c->get(IndexStore::class), $c->get(QueryParser::class)) extends Searcher {
            public int $searches = 0;

            public function search(string $query): array
            {
                $this->searches++;
                return Searcher::EMPTY;
            }
        };
        $c->set(Searcher::class, $spy);

        return $spy;
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejected(): iterable
    {
        yield '201 characters' => [str_repeat('a', 201), 'limited to 200 characters'];
        yield '11 terms'       => [implode(' ', array_map(fn ($i) => "term$i", range(1, 11))), 'limited to 10 terms'];
    }

    #[DataProvider('rejected')]
    public function testOversizedQueryIsRefusedWithoutTouchingTheIndex(string $query, string $message): void
    {
        $spy = $this->spySearcher();
        $this->client->request('GET', '/search/' . rawurlencode($query));

        $this->assertResponseStatusCodeSame(400);
        $this->assertSelectorTextContains('[role="alert"]', $message);
        $this->assertSame(0, $spy->searches, 'refused queries must not reach the index');
    }

    /** @return iterable<string, array{string}> */
    public static function accepted(): iterable
    {
        yield '200 characters' => [str_repeat('a', 200)];
        yield '10 terms'       => [implode(' ', array_map(fn ($i) => "term$i", range(1, 10)))];
        yield 'normal'         => ['windows firewall'];
    }

    #[DataProvider('accepted')]
    public function testQueriesAtTheLimitStillSearch(string $query): void
    {
        $spy = $this->spySearcher();
        $this->client->request('GET', '/search/' . rawurlencode($query));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[role="alert"]');
        $this->assertSame(1, $spy->searches);
    }

    public function testSearchIsRateLimitedPerIp(): void
    {
        $this->spySearcher();
        $ip = '203.0.113.90';
        static::getContainer()->get('limiter.search')->create($ip)->reset();

        for ($i = 0; $i < 30; $i++) {
            $this->client->request('GET', '/search/ssh', [], [], ['REMOTE_ADDR' => $ip]);
            $this->assertResponseIsSuccessful("search $i should be under the limit");
        }
        $this->client->request('GET', '/search/ssh', [], [], ['REMOTE_ADDR' => $ip]);
        $this->assertResponseStatusCodeSame(429);
        $this->assertNotEmpty($this->client->getResponse()->headers->get('Retry-After'));

        // A different IP is unaffected.
        $this->client->request('GET', '/search/ssh', [], [], ['REMOTE_ADDR' => '203.0.113.91']);
        $this->assertResponseIsSuccessful();
        static::getContainer()->get('limiter.search')->create('203.0.113.91')->reset();
    }
}
