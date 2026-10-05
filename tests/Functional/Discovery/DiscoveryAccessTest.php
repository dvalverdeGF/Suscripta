<?php

declare(strict_types=1);

namespace App\Tests\Functional\Discovery;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La bandeja de propuestas contiene datos deducidos del correo de una persona:
 * no puede ser accesible sin sesión.
 */
final class DiscoveryAccessTest extends WebTestCase
{
    public function testAnAnonymousVisitorIsRedirectedToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/discoveries');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }
}
