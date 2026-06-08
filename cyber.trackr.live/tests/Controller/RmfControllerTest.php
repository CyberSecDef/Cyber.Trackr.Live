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
}
