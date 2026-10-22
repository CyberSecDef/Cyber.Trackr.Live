<?php

namespace App\Tests\Controller;

use App\Service\MitigationGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Review S8, at the API: the reply says why there's no statement, a
 * "try later" reply is never publicly cached, and "4" / "04" share one
 * cached mitigation. The generator is a stub; nothing calls Gemini.
 */
class MitigationApiTest extends WebTestCase
{
    private const URL = '/api/stig/Application_Security_and_Development/%s/8/V-69239';

    private KernelBrowser $client;
    private string $script;
    private RateLimiterFactory $perClient;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->script = tempnam(sys_get_temp_dir(), 'mitapi_') ?: '';
    }

    protected function tearDown(): void
    {
        @unlink($this->script);
        parent::tearDown();
    }

    /** Swap in an enabled generator whose Python call is stubbed. */
    private function stubGenerator(int $perClient = 30): MitigationGenerator
    {
        $limiter = fn (string $id, int $limit) => new RateLimiterFactory(
            ['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        $this->perClient = $limiter('m', $perClient);
        $stub = new class(new ArrayAdapter(), $this->perClient, $limiter('mg', 200), $this->script) extends MitigationGenerator {
            public int $calls = 0;

            protected function runProcess(array $cmd): ?string
            {
                $this->calls++;
                return 'STUB MITIGATION for ' . $cmd[3] . 'R' . $cmd[4];
            }
        };
        static::getContainer()->set(MitigationGenerator::class, $stub);

        return $stub;
    }

    /** @return array{0: array<string,mixed>, 1: string} decoded body and Cache-Control */
    private function ask(string $version, string $ip = '203.0.113.80'): array
    {
        $this->client->request('GET', sprintf(self::URL, $version));
        $marker = sha1(json_decode($this->client->getResponse()->getContent(), true)['requirement-title']);

        $this->client->request('GET', sprintf(self::URL, $version) . '?m=' . $marker, [], [], ['REMOTE_ADDR' => $ip]);
        $this->assertResponseIsSuccessful();

        return [
            json_decode($this->client->getResponse()->getContent(), true),
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        ];
    }

    public function testGeneratedStatementIsReturnedAndCacheable(): void
    {
        $this->stubGenerator();
        [$json, $cc] = $this->ask('4');

        $this->assertSame('ok', $json['mitigation-status']);
        $this->assertSame('STUB MITIGATION for 4R8', $json['mitigation-statement']);
        $this->assertStringContainsString('public', $cc);
    }

    public function testZeroPaddedVersionSharesTheCachedStatement(): void
    {
        $stub = $this->stubGenerator();
        $this->ask('4');
        [$json] = $this->ask('04');
        $this->ask('004');

        $this->assertSame('STUB MITIGATION for 4R8', $json['mitigation-statement'], 'canonical toc version reaches the generator');
        $this->assertSame(1, $stub->calls, '"4", "04" and "004" must not each call Gemini');
    }

    public function testRateLimitedReplySaysSoAndIsNotCached(): void
    {
        $stub = $this->stubGenerator(perClient: 1);
        $this->perClient->create('203.0.113.80')->consume(1); // this IP's hour is used up
        [$json, $cc] = $this->ask('4');

        $this->assertSame('rate_limited', $json['mitigation-status']);
        $this->assertGreaterThan(0, $json['mitigation-retry-after']);
        $this->assertArrayNotHasKey('mitigation-statement', $json);
        $this->assertStringContainsString('no-store', $cc);
        $this->assertStringNotContainsString('public', $cc);
        $this->assertSame(0, $stub->calls);
    }

    public function testDisabledFeatureReportsUnavailable(): void
    {
        // Test env has no MITIGATION_SCRIPT, so the real service is disabled.
        [$json, $cc] = $this->ask('4');

        $this->assertSame('unavailable', $json['mitigation-status']);
        $this->assertStringContainsString('no-store', $cc);
    }

    public function testPlainVulnRequestIsUnchanged(): void
    {
        $this->client->request('GET', sprintf(self::URL, '4'));
        $json = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayNotHasKey('mitigation-status', $json);
        $this->assertStringContainsString('public', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }
}
