<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Review S17: unknown titles and CCIs must be a clean 404. These all used to
 * read $stigs[$title] / $scaps[$title] unchecked (or call ->attributes() on a
 * failed CCI lookup) and return 500 with a stack trace in the log.
 */
class UnknownResourceTest extends WebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function unknown(): iterable
    {
        yield 'api stig summary'   => ['/api/stig/NoSuchTitle/1/1'];
        yield 'api stig vuln'      => ['/api/stig/NoSuchTitle/1/1/V-1'];
        yield 'api scap summary'   => ['/api/scap/NoSuchTitle/1/1'];
        yield 'api scap vuln'      => ['/api/scap/NoSuchTitle/1/1/V-1'];
        yield 'api cci unknown'    => ['/api/cci/CCI-999999'];
        yield 'api cci garbage'    => ['/api/cci/garbage'];
        yield 'stig view'          => ['/stig/NoSuchTitle/1/1'];
        yield 'stig compare'       => ['/stig/NoSuchTitle/1/1/1/2'];
        yield 'stig download'      => ['/stig/NoSuchTitle/1/1/download'];
        yield 'scap view'          => ['/scap/NoSuchTitle/1/1'];
        yield 'scap download'      => ['/scap/NoSuchTitle/1/1/download'];
    }

    #[DataProvider('unknown')]
    public function testUnknownTitleOrCciIs404(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testKnownCciStillResolves(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/cci/CCI-000054');
        $this->assertResponseIsSuccessful();
        $this->assertSame('CCI-000054', json_decode($client->getResponse()->getContent(), true)['cci'] ?? null);
    }
}
