<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Review S9: security headers on every response, in sync across PHP, Apache and nginx. */
class SecurityHeadersTest extends WebTestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function responses(): iterable
    {
        yield 'HTML page'   => ['/stig', 200];
        yield 'home'        => ['/', 200];
        yield 'JSON API'    => ['/api', 200];
        yield 'download'    => ['/stig/Application_Security_and_Development/4/8/download', 200];
        yield 'not found'   => ['/no-such-page', 404];
    }

    #[DataProvider('responses')]
    public function testEveryResponseCarriesTheHeaders(string $url, int $status): void
    {
        $client = static::createClient();
        $client->request('GET', $url);
        $this->assertResponseStatusCodeSame($status);

        $headers = $client->getResponse()->headers;
        foreach (SecurityHeadersSubscriber::HEADERS as $name => $value) {
            $this->assertSame([$value], $headers->all(strtolower($name)), "$name on $url");
        }
    }

    public function testCspCoversTheEssentials(): void
    {
        $csp = SecurityHeadersSubscriber::CSP;
        foreach (["default-src 'self'", "connect-src 'self'", "frame-ancestors 'none'", "object-src 'none'", "base-uri 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $csp);
        }
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringNotContainsString('*', $csp, 'no wildcard sources');
    }

    /** Static files bypass PHP, so .htaccess and nginx.conf carry copies. They must match. */
    public function testApacheAndNginxCopiesMatch(): void
    {
        $root = dirname(__DIR__, 2);
        $htaccess = file_get_contents($root . '/public/.htaccess');
        $nginx = file_get_contents($root . '/docker/nginx.conf');

        foreach (SecurityHeadersSubscriber::HEADERS as $name => $value) {
            $this->assertStringContainsString(sprintf('Header always set %s "%s"', $name, $value), $htaccess, "$name in .htaccess");
            $this->assertStringContainsString(sprintf('add_header %s "%s" always;', $name, $value), $nginx, "$name in nginx.conf");
        }
        $this->assertSame(count(SecurityHeadersSubscriber::HEADERS), substr_count($htaccess, 'Header always set'), 'no extra or stale .htaccess headers');
        $this->assertSame(count(SecurityHeadersSubscriber::HEADERS), substr_count($nginx, 'add_header'), 'no extra or stale nginx headers');
    }
}
