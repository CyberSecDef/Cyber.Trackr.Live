<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RmfControllerTest extends WebTestCase
{
    #[DataProvider('rmfPages')]
    public function testRmfPageRenders(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);
        $this->assertResponseIsSuccessful();
    }

    public static function rmfPages(): array
    {
        return [
            'rev5'      => ['/rmf/5'],
            'rev4'      => ['/rmf/4'],
            'r4-to-r5'  => ['/rmf/4-to-5'],
            'baselines' => ['/baselines'],
        ];
    }

    /**
     * P8: assessment procedures and related-control titles now come from maps
     * built once in RmfController instead of per-control loops/XPath in Twig.
     * AC-2 has 24 APs on both pages; AC-3 is among its related controls.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('revisions')]
    public function testControlShowsItsProceduresAndRelatedTitles(string $path): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $path);
        $this->assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        $start = strpos($html, 'id="rmf_AC-2"');
        $block = substr($html, $start, strpos($html, 'id="rmf_AC-3"') - $start);
        $this->assertSame(24, substr_count($block, '<dt >'), 'AC-2 assessment procedures');
        $this->assertStringContainsString('CCI-002110', $block);
        $this->assertMatchesRegularExpression('/href="#rmf_AC-3">.*?<\/a>\s*-\s*Access Enforcement/s', $block);
    }

    /** @return iterable<string, array{string}> */
    public static function revisions(): iterable
    {
        yield 'rev 5' => ['/rmf/5'];
        yield 'rev 4' => ['/rmf/4'];
    }
}
