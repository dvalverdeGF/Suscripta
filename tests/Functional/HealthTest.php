<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use const JSON_THROW_ON_ERROR;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testHealthEndpointReportsDatabaseStatus(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('ok', $payload['status']);
        self::assertSame('ok', $payload['database']);
    }

    public function testLoginPageIsReachable(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Entrar en Suscripta');
    }

    public function testAnonymousVisitorIsRedirectedToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseRedirects();
        self::assertStringEndsWith('/login', (string) $client->getResponse()->headers->get('Location'));
    }
}
