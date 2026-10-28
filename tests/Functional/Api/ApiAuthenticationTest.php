<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\Application\ApiKeyFactory;
use App\Api\Domain\Entity\ApiKey;
use App\Api\Domain\Enum\ApiScope;
use App\Api\Domain\Repository\ApiKeyRepositoryInterface;
use App\Tests\Support\Api\IssuesApiKeys;
use DateTimeImmutable;

use function str_contains;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Quién puede entrar en la API y quién no (Fase 18).
 *
 * ## Los cuatro «no» que importan
 *
 * Una credencial puede fallar de cuatro maneras y las cuatro tienen que dar
 * `401`, porque para quien llama son lo mismo: «no puedes pasar». Lo que
 * cambia es el motivo, que el panel enseña al operador.
 *
 * 1. No hay credencial.
 * 2. La clave no existe.
 * 3. La clave se revocó.
 * 4. La clave caducó.
 *
 * ## Por qué también se prueba `X-Api-Key`
 *
 * El autenticador acepta las dos cabeceras. `Authorization` es la estándar y
 * `X-Api-Key` es la que sobrevive a intermediarios que borran la primera. Si
 * una de las dos dejara de funcionar, media integración se caería sin que
 * ningún test lo notara.
 */
final class ApiAuthenticationTest extends WebTestCase
{
    use IssuesApiKeys;

    public function testARequestWithoutCredentialIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/services');

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('content-type'),
        );
    }

    public function testAnUnknownKeyIsRejected(): void
    {
        $client = self::createClient();

        $this->apiGet($client, '/api/v1/services', 'sk_esta_clave_no_existe_en_ninguna_parte');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAValidKeyIsAcceptedThroughTheAuthorizationHeader(): void
    {
        $client = self::createClient();
        ['organization' => $organization] = $this->registerOrganizationUser();
        ['secret' => $secret] = $this->issueApiKey($organization);

        $this->apiGet($client, '/api/v1/services', $secret);

        self::assertResponseIsSuccessful();
    }

    public function testAValidKeyIsAcceptedThroughTheApiKeyHeader(): void
    {
        $client = self::createClient();
        ['organization' => $organization] = $this->registerOrganizationUser();
        ['secret' => $secret] = $this->issueApiKey($organization);

        $client->request('GET', '/api/v1/services', server: ['HTTP_X_API_KEY' => $secret]);

        self::assertResponseIsSuccessful();
    }

    public function testARevokedKeyStopsWorkingImmediately(): void
    {
        $client = self::createClient();
        ['organization' => $organization] = $this->registerOrganizationUser();
        ['key' => $key, 'secret' => $secret] = $this->issueApiKey($organization);

        // Antes de revocar, la clave sirve: si no, el test no probaría nada.
        $this->apiGet($client, '/api/v1/services', $secret);
        self::assertResponseIsSuccessful();

        $this->revokeApiKey($organization, $key);

        $this->apiGet($client, '/api/v1/services', $secret);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnExpiredKeyIsRejected(): void
    {
        $client = self::createClient();
        ['organization' => $organization] = $this->registerOrganizationUser();

        $factory = self::getContainer()->get(ApiKeyFactory::class);
        $generated = $factory->generate();

        $expired = new ApiKey(
            organizationId: $organization->getId(),
            name: 'Caducada',
            prefix: $generated['prefix'],
            secretHash: $generated['hash'],
            scopes: [ApiScope::Read],
            expiresAt: new DateTimeImmutable('-1 day'),
        );

        self::getContainer()->get(ApiKeyRepositoryInterface::class)->save($expired);

        $this->apiGet($client, '/api/v1/services', $generated['secret']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheRejectionDoesNotRevealWhetherTheKeyExists(): void
    {
        $client = self::createClient();
        ['organization' => $organization] = $this->registerOrganizationUser();
        ['key' => $key, 'secret' => $revokedSecret] = $this->issueApiKey($organization);

        $this->revokeApiKey($organization, $key);

        // Una clave revocada y una inventada tienen que responder lo mismo:
        // distinguirlas ayudaría a quien prueba credenciales robadas.
        $this->apiGet($client, '/api/v1/services', $revokedSecret);
        $revokedBody = (string) $client->getResponse()->getContent();

        $this->apiGet($client, '/api/v1/services', 'sk_inventada_000000000000000000');
        $inventedBody = (string) $client->getResponse()->getContent();

        self::assertSame($revokedBody, $inventedBody);
        self::assertFalse(str_contains($revokedBody, 'revoc'));
    }
}
