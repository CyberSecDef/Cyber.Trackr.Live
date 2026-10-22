<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * StigCompanionZipFinder used to accept only [A-Za-z0-9_-] titles and
 * digits-only versions/releases, so 492 STIG versions with ".", "(", ")",
 * ",", ":", "&" in the title or a release like "0.1" never showed their
 * companion documents.
 */
class CompanionDocsTest extends WebTestCase
{
    /** @return iterable<string, array{string, string, string, string}> */
    public static function stigs(): iterable
    {
        yield 'dot in title'      => ['APACHE_2.2_Server_for_UNIX', '1', '11', 'U_Apache_2-2_UNIX_V1R11_Overview.pdf'];
        yield 'parentheses'       => ['Apple_OS_X_10.15_(Catalina)', '1', '10', 'U_Apple_OS_X_10-15_STIG_V1_Release_Memo.pdf'];
        yield 'commas'            => ['Authentication,_Authorization,_and_Accounting_Services_(AAA)_Security_Requirements_Guide', '1', '2', 'U_AAA_Services_V1R2_Overview.pdf'];
        yield 'release 0.1'       => ['Application_Programming_Interface_(API)_Security_Requirements_Guide', '1', '0.1', 'U_Readme_SRG_and_STIG.pdf'];
    }

    private function base(string $title, string $version, string $release): string
    {
        return '/stig/' . rawurlencode($title) . "/$version/$release";
    }

    #[DataProvider('stigs')]
    public function testCompanionDocumentsAreReachable(string $title, string $version, string $release, string $pdf): void
    {
        $data = dirname(__DIR__, 2) . '/resources/data';
        if (!is_file("$data/companion_zip_index.json")) {
            $this->markTestSkipped('companion_zip_index.json not built');
        }
        $client = static::createClient();

        // The view page lists the companion documents...
        $crawler = $client->request('GET', $this->base($title, $version, $release));
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('a[href*="companion.zip"]')->count(), 'companion ZIP link shown');

        // ...the ZIP route redirects to the static file...
        $client->request('GET', $this->base($title, $version, $release) . '/companion.zip');
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringStartsWith('/downloads/zips/U_', (string) $client->getResponse()->headers->get('Location'));

        // ...and a PDF inside it is served.
        $client->request('GET', $this->base($title, $version, $release) . '/companion.pdf?file=' . rawurlencode($pdf));
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'path traversal in title' => ['/stig/..%2F..%2Fetc/1/1/companion.zip'];
        yield 'slash-free junk title'   => ['/stig/%3Cscript%3E/1/1/companion.zip'];
        yield 'unknown title'           => ['/stig/No_Such_Title/1/1/companion.zip'];
        yield 'traversal in pdf entry'  => ['/stig/APACHE_2.2_Server_for_UNIX/1/11/companion.pdf?file=../../../etc/passwd.pdf'];
        yield 'non-pdf entry'           => ['/stig/APACHE_2.2_Server_for_UNIX/1/11/companion.pdf?file=U_Apache_2-2_UNIX_V1R11_Manual-xccdf.xml'];
    }

    #[DataProvider('rejected')]
    public function testBadInputStillGets404(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);
        $this->assertResponseStatusCodeSame(404);
    }
}
