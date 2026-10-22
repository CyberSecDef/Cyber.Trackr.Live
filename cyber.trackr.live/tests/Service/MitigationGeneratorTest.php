<?php

namespace App\Tests\Service;

use App\Service\MitigationGenerator;
use App\Service\MitigationRateLimitedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Security-focused tests for the hardened mitigation generator. The process
 * invocation is stubbed (runProcess overridden), so nothing is ever executed —
 * these exercise the guards that replaced the old shell_exec() RCE.
 */
class MitigationGeneratorTest extends TestCase
{
    /** @var string[] */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        $this->tmpFiles = [];
    }

    public function testDisabledWhenNoScriptConfigured(): void
    {
        $gen = $this->generator(scriptPath: '');
        $this->assertFalse($gen->isEnabled());
        $this->assertNull($gen->generate('Application_Security_and_Development', '4', '8', 'V-69239', '203.0.113.7'));
        $this->assertSame(0, $gen->calls, 'a disabled generator must never spawn a process');
    }

    public function testValidInputReturnsOutputAndCachesIt(): void
    {
        $gen = $this->generator();
        $out1 = $gen->generate('Application_Security_and_Development', '4', '8', 'V-69239', '203.0.113.7');
        $out2 = $gen->generate('Application_Security_and_Development', '4', '8', 'V-69239', '203.0.113.7');

        $this->assertSame('STUB MITIGATION', $out1);
        $this->assertSame('STUB MITIGATION', $out2);
        $this->assertSame(1, $gen->calls, 'second identical request must be served from cache');
    }

    /**
     * The values that used to be interpolated into /bin/sh. Each must be
     * rejected before any process is attempted.
     *
     */
    #[DataProvider('injectionVectors')]
    public function testRejectsInjectionAndMalformedInput(string $title, string $version, string $release, string $id): void
    {
        $gen = $this->generator();
        $this->assertNull($gen->generate($title, $version, $release, $id, '203.0.113.7'));
        $this->assertSame(0, $gen->calls, 'malicious/invalid input must never reach the process');
    }

    public static function injectionVectors(): array
    {
        return [
            'shell metachars in id'   => ['App_Sec', '4', '8', 'V-1; rm -rf /'],
            'command sub in title'    => ['$(touch pwned)', '4', '8', 'V-69239'],
            'backtick in title'       => ['`id`', '4', '8', 'V-69239'],
            'pipe in release'         => ['App_Sec', '4', '8|cat', 'V-69239'],
            'argument injection title'=> ['-rf', '4', '8', 'V-69239'],
            'argument injection id'   => ['App_Sec', '4', '8', '--output=/etc/x'],
            'non-V id'                => ['App_Sec', '4', '8', 'SV-1234'],
            'newline in id'           => ['App_Sec', '4', '8', "V-1\nV-2"],
            'empty id'                => ['App_Sec', '4', '8', ''],
            'non-numeric version'     => ['App_Sec', '4a', '8', 'V-69239'],
        ];
    }

    public function testPerClientRateLimitBlocksSecondDistinctVuln(): void
    {
        $gen = $this->generator(perClient: 1);

        // First (cache-miss) request from this IP consumes the only token.
        $this->assertSame('STUB MITIGATION', $gen->generate('App_Sec', '4', '8', 'V-1', '198.51.100.9'));
        // A different vuln (also a cache miss) from the same IP is now refused, with a retry hint.
        try {
            $gen->generate('App_Sec', '4', '8', 'V-2', '198.51.100.9');
            $this->fail('expected MitigationRateLimitedException');
        } catch (MitigationRateLimitedException $e) {
            $this->assertGreaterThan(0, $e->retryAfterSeconds);
        }
        $this->assertSame(1, $gen->calls);

        // A different client still gets through.
        $this->assertSame('STUB MITIGATION', $gen->generate('App_Sec', '4', '8', 'V-2', '198.51.100.10'));
    }

    public function testCacheHitDoesNotConsumeRateLimit(): void
    {
        $gen = $this->generator(perClient: 1);
        $gen->generate('App_Sec', '4', '8', 'V-1', '198.51.100.20');          // miss -> spends the token
        $this->assertSame('STUB MITIGATION', $gen->generate('App_Sec', '4', '8', 'V-1', '198.51.100.20')); // hit
        $this->assertSame(1, $gen->calls);
    }

    /**
     * S8: the old counter only set its expiry on the first save, so once a
     * second request landed it never expired and the IP stayed locked out
     * until the cache was cleared. The window must actually reset.
     */
    public function testLimitResetsWhenTheWindowEnds(): void
    {
        $gen = $this->generator(perClient: 2, interval: '2 seconds');
        $ip = '198.51.100.30';
        $gen->generate('App_Sec', '4', '8', 'V-1', $ip);
        $gen->generate('App_Sec', '4', '8', 'V-2', $ip);
        try {
            $gen->generate('App_Sec', '4', '8', 'V-3', $ip);
            $this->fail('third miss in the window should be refused');
        } catch (MitigationRateLimitedException) {
        }

        sleep(3);
        $this->assertSame('STUB MITIGATION', $gen->generate('App_Sec', '4', '8', 'V-3', $ip));
        $this->assertSame(3, $gen->calls);
    }

    public function testGlobalCapAppliesAcrossClients(): void
    {
        $gen = $this->generator(perClient: 30, global: 2);
        $gen->generate('App_Sec', '4', '8', 'V-1', '198.51.100.41');
        $gen->generate('App_Sec', '4', '8', 'V-2', '198.51.100.42');

        $this->expectException(MitigationRateLimitedException::class);
        $gen->generate('App_Sec', '4', '8', 'V-3', '198.51.100.43'); // a fresh IP, but the site is at its cap
    }

    private function generator(?string $scriptPath = null, int $perClient = 30, int $global = 200, string $interval = '1 hour'): TestableMitigationGenerator
    {
        if ($scriptPath === null) {
            $scriptPath = tempnam(sys_get_temp_dir(), 'mitgen_') ?: '';
            $this->tmpFiles[] = $scriptPath;
        }
        $limiter = fn (string $id, int $limit) => new RateLimiterFactory(
            ['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => $interval],
            new InMemoryStorage(),
        );

        return new TestableMitigationGenerator(
            new ArrayAdapter(),
            $limiter('mitigation', $perClient),
            $limiter('mitigation_global', $global),
            $scriptPath,
            '/usr/bin/python3',
            25,
        );
    }
}

/** Generator whose process call is stubbed, recording how often it would run. */
class TestableMitigationGenerator extends MitigationGenerator
{
    public int $calls = 0;

    protected function runProcess(array $cmd): ?string
    {
        $this->calls++;
        return 'STUB MITIGATION';
    }
}
