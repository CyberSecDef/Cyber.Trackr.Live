<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApiControllerTest extends WebTestCase
{
    // A STIG known to exist in stig_toc.json (V4R8). The wizard test covers V6R4.
    private const STIG_TITLE   = 'Application_Security_and_Development';
    private const STIG_VERSION = '4';
    private const STIG_RELEASE = '8';
    private const STIG_VULN    = 'V-69239';

    public function testApiSummaryRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api');
        $this->assertResponseIsSuccessful();
    }

    public function testCciListReturnsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/cci');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
    }

    public function testCciShowReturnsControl(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/cci/CCI-000001');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('CCI-000001', $data['cci'] ?? null);
    }

    public function testRmfV4ListReturnsControls(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/rmf/4');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('controls', $data);
        $this->assertNotEmpty($data['controls']);
    }

    public function testRmfV5ListReturnsControls(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/rmf/5');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('controls', $data);
        $this->assertNotEmpty($data['controls']);
    }

    public function testStigListReturnsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stig');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
    }

    public function testStigSummaryReturnsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', sprintf(
            '/api/stig/%s/%s/%s',
            self::STIG_TITLE,
            self::STIG_VERSION,
            self::STIG_RELEASE
        ));

        $this->assertResponseIsSuccessful();
    }

    public function testStigVulnReturnsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', sprintf(
            '/api/stig/%s/%s/%s/%s',
            self::STIG_TITLE,
            self::STIG_VERSION,
            self::STIG_RELEASE,
            self::STIG_VULN
        ));

        $this->assertResponseIsSuccessful();
    }

    // Exercises the guard added in commit 8710c31: a valid title with an unknown
    // version/release resolves to no STIG file and must 404, not fatal with 500.
    public function testStigSummaryUnknownVersionIs404(): void
    {
        $client = static::createClient();
        $client->request('GET', sprintf('/api/stig/%s/99/99', self::STIG_TITLE));
        $this->assertResponseStatusCodeSame(404);
    }

    // S3: {vuln} and {con} were interpolated into XPath unescaped. A nested
    // count() payload ran for minutes inside libxml; route requirements now
    // reject anything that isn't an ID before the controller runs.
    private const XPATH_PAYLOAD = "x' or count(//*[count(//*)>0])>0 or @id='x";

    /** @return iterable<string, array{string}> */
    public static function rejectedIdUrls(): iterable
    {
        $stig = sprintf('/api/stig/%s/%s/%s/', self::STIG_TITLE, self::STIG_VERSION, self::STIG_RELEASE);

        yield 'stig vuln injection' => [$stig . rawurlencode(self::XPATH_PAYLOAD)];
        yield 'stig vuln unknown'   => [$stig . 'V-999999999'];
        yield 'rmf 4 injection'     => ['/api/rmf/4/' . rawurlencode(self::XPATH_PAYLOAD)];
        yield 'rmf 5 injection'     => ['/api/rmf/5/' . rawurlencode(self::XPATH_PAYLOAD)];
        yield 'rmf 5 unknown'       => ['/api/rmf/5/ZZ-999'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedIdUrls')]
    public function testMalformedOrUnknownIdIs404(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testRmfV5ShowAcceptsLowercaseControl(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/rmf/5/ac-2');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('AC-2', $data['number'] ?? null);
    }
}
