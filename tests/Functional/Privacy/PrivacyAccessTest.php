<?php

declare(strict_types=1);

namespace App\Tests\Functional\Privacy;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El panel de privacidad es un recurso **privado**.
 *
 * Va en su propia clase porque un kernel de Symfony solo se puede arrancar una
 * vez por test: el caso anónimo necesita un cliente limpio.
 */
final class PrivacyAccessTest extends WebTestCase
{
    public function testAnAnonymousVisitorIsSentToTheLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/privacy');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testAnAnonymousVisitorCannotExportTheData(): void
    {
        $client = self::createClient();
        $client->request('GET', '/privacy/export.json');

        self::assertResponseRedirects();
    }

    public function testAnAnonymousVisitorCannotDeleteTheData(): void
    {
        $client = self::createClient();
        $client->request('POST', '/privacy/delete', ['confirmation' => 'Acme']);

        // El cortafuegos manda al identificarse: sin sesión no hay borrado.
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }
}
