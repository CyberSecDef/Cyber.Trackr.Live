<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CciControllerTest extends WebTestCase
{
    public function testCciPageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cci');
        $this->assertResponseIsSuccessful();
    }
}
