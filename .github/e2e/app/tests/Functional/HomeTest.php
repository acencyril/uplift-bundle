<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeTest extends WebTestCase
{
    public function testHome(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('Hello, e2e', $client->getResponse()->getContent());
    }

    public function testLegacyCommandStillRuns(): void
    {
        $kernel = self::bootKernel();
        $app = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($app->find('app:legacy-hello'));

        self::assertSame(0, $tester->execute([]));
        self::assertSame("hello\n", $tester->getDisplay());
    }
}
