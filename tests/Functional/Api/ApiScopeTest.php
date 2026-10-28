<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\Domain\Enum\ApiScope;
use App\Tests\Support\Api\IssuesApiKeys;
use App\Tests\Support\Identity\SwitchesPlan;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Los ámbitos de una clave (Fase 18).
 *
 * ## La propiedad que se prueba
 *
 * Una clave de solo lectura **no puede escribir**. No es un detalle: es la
 * razón de que existan los ámbitos. Un integrador que solo consulta el
 * inventario recibe una clave de lectura y, si se filtra, el daño se queda en
 * una fuga de datos y no llega a tocar la facturación.
 *
 * ## `write` incluye `read`
 *
 * Quien puede corregir un servicio tiene que poder verlo. La regla se aplica
 * al emitir, así que la clave de escritura guarda los dos ámbitos y el panel
 * los enseña los dos.
 */
final class ApiScopeTest extends WebTestCase
{
    use IssuesApiKeys;
    use SwitchesPlan;

    public function testAReadOnlyKeyCannotWrite(): void
    {
        $client = self::createClient();
        ['user' => $user, 'organization' => $organization] = $this->registerOrganizationUser();
        $this->switchPlanOfFirstMembership($user);
        ['secret' => $secret] = $this->issueApiKey($organization, 'Solo lectura', [ApiScope::Read]);

        $client->request(
            'POST',
            '/api/v1/services',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json'],
            content: '{"name":"Servicio de prueba"}',
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testAReadOnlyKeyCanRead(): void
    {
        $client = self::createClient();
        ['organization' => $organization] = $this->registerOrganizationUser();
        ['secret' => $secret] = $this->issueApiKey($organization, 'Solo lectura', [ApiScope::Read]);

        $this->apiGet($client, '/api/v1/services', $secret);

        self::assertResponseIsSuccessful();
    }

    public function testAWriteKeyCanWrite(): void
    {
        $client = self::createClient();
        ['user' => $user, 'organization' => $organization] = $this->registerOrganizationUser();
        $this->switchPlanOfFirstMembership($user);
        ['secret' => $secret] = $this->issueApiKey($organization, 'Escritura', [ApiScope::Write]);

        $client->request(
            'POST',
            '/api/v1/services',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json'],
            content: '{"name":"Servicio de prueba"}',
        );

        self::assertResponseStatusCodeSame(201);
    }

    public function testAWriteKeyAlsoCarriesRead(): void
    {
        $client = self::createClient();
        ['organization' => $organization] = $this->registerOrganizationUser();
        ['key' => $key, 'secret' => $secret] = $this->issueApiKey($organization, 'Escritura', [ApiScope::Write]);

        // Se guarda explícito, no se deduce al comprobar: el panel tiene que
        // poder enseñar los dos ámbitos que la clave lleva de verdad.
        $scopes = array_map(static fn (ApiScope $scope): string => $scope->value, $key->getScopes());

        self::assertContains('read', $scopes);
        self::assertContains('write', $scopes);

        $this->apiGet($client, '/api/v1/services', $secret);
        self::assertResponseIsSuccessful();
    }
}
