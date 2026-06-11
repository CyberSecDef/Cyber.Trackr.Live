<?php

namespace App\Tests\EventSubscriber;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApiRateLimitTest extends WebTestCase
{
    /**
     * After the configured per-IP limit (120/min) is exceeded, /api requests
     * must return 429 with a Retry-After header.
     */
    public function testApiIsRateLimitedPerIp(): void
    {
        $client = static::createClient();
        $client->disableReboot();          // keep one kernel so the flood is fast
        $ip = '203.0.113.55';

        // Deterministic start: clear this synthetic IP's window so prior runs
        // (within the same minute) can't affect the result.
        static::getContainer()->get('limiter.api')->create($ip)->reset();

        $server = ['REMOTE_ADDR' => $ip];

        // First request must pass.
        $client->request('GET', '/api', [], [], $server);
        $this->assertResponseIsSuccessful();

        // Within the next ~120 it must start returning 429.
        $got429 = false;
        for ($i = 0; $i < 200; $i++) {
            $client->request('GET', '/api', [], [], $server);
            if (429 === $client->getResponse()->getStatusCode()) {
                $got429 = true;
                $this->assertNotEmpty(
                    $client->getResponse()->headers->get('Retry-After'),
                    '429 response should carry a Retry-After header'
                );
                break;
            }
        }
        $this->assertTrue($got429, 'API should return 429 once the rate limit is exceeded');
    }

    /**
     * Non-/api routes must never be throttled, even when the same IP has
     * exhausted its API budget.
     */
    public function testNonApiPathIsNotRateLimited(): void
    {
        $client = static::createClient();
        $ip = '203.0.113.56';

        // Drain this IP's API limiter directly, then prove a normal page still loads.
        $limiter = static::getContainer()->get('limiter.api')->create($ip);
        $limiter->reset();
        for ($i = 0; $i < 121; $i++) {
            $limiter->consume(1);
        }

        $client->request('GET', '/', [], [], ['REMOTE_ADDR' => $ip]);
        $this->assertResponseIsSuccessful();
    }
}
