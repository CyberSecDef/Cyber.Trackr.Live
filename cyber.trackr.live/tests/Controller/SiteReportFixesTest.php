<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Findings from the 2026-10-16 live-site report: API errors as JSON (F5),
 * read-only routes refuse other methods (F16), no dead source maps (F13), a
 * real /favicon.ico (F17) and a security.txt (F14).
 */
class SiteReportFixesTest extends WebTestCase
{
    private const PUBLIC = __DIR__ . '/../../public';

    /** @return iterable<string, array{string, string, int}> */
    public static function apiErrors(): iterable
    {
        yield 'unknown control'   => ['GET', '/api/rmf/5/ZZ-999', 404];
        yield 'trailing quote'    => ['GET', "/api/rmf/5/AC-1'", 404];
        yield 'unknown CCI'       => ['GET', '/api/cci/CCI-999999999', 404];
        yield 'unknown STIG'      => ['GET', '/api/stig/x/1/1', 404];
        yield 'malformed CVE'     => ['GET', '/api/vulns/cve/notacve', 404];
        yield 'unknown endpoint'  => ['GET', '/api/no-such-thing', 404];
        yield 'POST to the index' => ['POST', '/api', 405];
        yield 'DELETE a record'   => ['DELETE', '/api/rmf/5/AC-1', 405];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('apiErrors')]
    public function testApiErrorsAreJson(string $method, string $url, int $status): void
    {
        $client = static::createClient();
        $client->request($method, $url);
        $response = $client->getResponse();

        $this->assertSame($status, $response->getStatusCode(), "$method $url");
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame($status, $body['status'] ?? null);
        $this->assertNotEmpty($body['error'] ?? null);
        $this->assertStringNotContainsString('No route found', $body['error'], 'no router internals');
        if ($status === 405) {
            $this->assertSame('GET', $response->headers->get('Allow'), 'HEAD is implied by GET');
        }
    }

    public function testRateLimitIsJsonWithRetryAfter(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $ip = '203.0.113.77';
        static::getContainer()->get('limiter.api')->create($ip)->reset();
        for ($i = 0; $i < 200; $i++) {
            $client->request('GET', '/api', [], [], ['REMOTE_ADDR' => $ip]);
            if ($client->getResponse()->getStatusCode() === 429) {
                break;
            }
        }
        $response = $client->getResponse();
        $this->assertSame(429, $response->getStatusCode());
        $this->assertNotEmpty($response->headers->get('Retry-After'));
        $this->assertSame(429, json_decode((string) $response->getContent(), true)['status'] ?? null);
    }

    public function testPagesKeepTheirHtmlErrorsAndRefuseOtherMethods(): void
    {
        $client = static::createClient();
        $client->request('GET', '/stig/No_Such_Title/1/1');
        $this->assertResponseStatusCodeSame(404);
        $this->assertStringStartsWith('text/html', (string) $client->getResponse()->headers->get('Content-Type'));

        $client->request('POST', '/stig');
        $this->assertResponseStatusCodeSame(405);
        $client->request('HEAD', '/stig');
        $this->assertResponseIsSuccessful();
    }

    /** Every route says which methods it takes; read-only ones take GET (and so HEAD). */
    public function testNoRouteAcceptsAnyMethod(): void
    {
        static::bootKernel();
        $open = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            if (!str_starts_with($name, '_') && $route->getMethods() === []) {
                $open[] = $name . ' ' . $route->getPath();
            }
        }
        $this->assertSame([], $open);
    }

    public function testEveryDeclaredSourceMapExists(): void
    {
        $missing = [];
        foreach (array_merge(glob(self::PUBLIC . '/js/*.js'), glob(self::PUBLIC . '/css/*.css')) as $file) {
            preg_match_all('/sourceMappingURL=([^\s*]+)/', (string) file_get_contents($file), $m);
            foreach ($m[1] as $map) {
                if (!is_file(dirname($file) . '/' . $map)) {
                    $missing[] = basename($file) . ' -> ' . $map;
                }
            }
        }
        $this->assertSame([], $missing);
    }

    public function testFaviconIcoIsARealIcon(): void
    {
        $ico = (string) file_get_contents(self::PUBLIC . '/favicon.ico');
        $this->assertGreaterThan(1000, strlen($ico));
        $this->assertSame("\x00\x00\x01\x00", substr($ico, 0, 4), 'ICO header');
    }

    /** RFC 9116. Fails 30 days before Expires so it gets renewed in time. */
    public function testSecurityTxtIsValidAndNotAboutToExpire(): void
    {
        $txt = (string) file_get_contents(self::PUBLIC . '/.well-known/security.txt');
        $this->assertMatchesRegularExpression('/^Contact: mailto:\S+@\S+$/m', $txt);
        $this->assertMatchesRegularExpression('#^Canonical: https://cyber\.trackr\.live/\.well-known/security\.txt$#m', $txt);
        $this->assertSame(1, preg_match('/^Expires: (\S+)$/m', $txt, $m));
        $expires = new \DateTimeImmutable($m[1]);
        $this->assertGreaterThan(new \DateTimeImmutable('+30 days'), $expires, 'renew security.txt Expires');
        $this->assertLessThan(new \DateTimeImmutable('+1 year +1 day'), $expires, 'RFC 9116: at most a year ahead');
    }
}
