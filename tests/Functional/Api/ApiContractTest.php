<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\Domain\Enum\ApiScope;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Tests\Support\Api\IssuesApiKeys;
use App\Tests\Support\Identity\SwitchesPlan;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El contrato de respuestas de la API (Fase 18).
 *
 * ## Qué se fija aquí
 *
 * Un integrador escribe su cliente **una vez**. Si la forma de las respuestas
 * cambia sin avisar, su integración se rompe en producción un domingo. Estos
 * tests son el contrato:
 *
 * - Toda lista es `{data, meta}` y `meta` tiene las seis claves de paginación.
 * - Todo recurso suelto es `{data}`.
 * - Todo error es `application/problem+json`.
 * - El `422` trae `violations[]` con el campo y el mensaje, **en español**.
 *
 * ## Por qué se comprueba el idioma
 *
 * El `422` salía en inglés porque caía en la rama genérica de
 * `HttpExceptionInterface`, que usa `Response::$statusTexts`. El integrador no
 * puede hacer nada con «Unprocessable Entity»: necesita saber **qué campo**
 * está mal y **por qué**, en el idioma en que está escrita su aplicación.
 */
final class ApiContractTest extends WebTestCase
{
    use IssuesApiKeys;
    use SwitchesPlan;

    public function testACollectionIsDataPlusMeta(): void
    {
        $client = self::createClient();
        ['organization' => $organization] = $this->registerOrganizationUser();
        ['secret' => $secret] = $this->issueApiKey($organization);

        $this->apiGet($client, '/api/v1/services', $secret);
        self::assertResponseIsSuccessful();

        $body = $this->jsonBody($client);

        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('meta', $body);
        self::assertIsArray($body['data']);

        foreach (['page', 'per_page', 'total', 'total_pages', 'has_next', 'has_previous'] as $key) {
            self::assertArrayHasKey($key, $body['meta'], 'Falta '.$key.' en meta.');
        }
    }

    public function testASingleResourceIsWrappedInData(): void
    {
        $client = self::createClient();
        ['user' => $user, 'organization' => $organization] = $this->registerOrganizationUser();
        $this->switchPlanOfFirstMembership($user);
        ['secret' => $secret] = $this->issueApiKey($organization, 'Escritura', [ApiScope::Write]);

        $client->request(
            'POST',
            '/api/v1/services',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json'],
            content: '{"name":"Navegación web"}',
        );
        self::assertResponseStatusCodeSame(201);

        $body = $this->jsonBody($client);

        self::assertArrayHasKey('data', $body);
        self::assertSame('Navegación web', $body['data']['name']);
        self::assertArrayHasKey('current_price', $body['data']);
        self::assertArrayHasKey('prices', $body['data']);
    }

    public function testPaginationIsHonoured(): void
    {
        $client = self::createClient();
        ['organization' => $organization] = $this->registerOrganizationUser();
        ['secret' => $secret] = $this->issueApiKey($organization);

        $this->apiGet($client, '/api/v1/services?per_page=1&page=2', $secret);
        self::assertResponseIsSuccessful();

        $meta = $this->jsonBody($client)['meta'];

        self::assertSame(1, $meta['per_page']);
        self::assertSame(2, $meta['page']);
    }

    public function testAValidationErrorCarriesViolationsInSpanish(): void
    {
        $client = self::createClient();
        ['user' => $user, 'organization' => $organization] = $this->registerOrganizationUser();
        $this->switchPlanOfFirstMembership($user);
        ['secret' => $secret] = $this->issueApiKey($organization, 'Escritura', [ApiScope::Write]);

        $client->request(
            'POST',
            '/api/v1/services',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json'],
            content: '{"name":""}',
        );

        self::assertResponseStatusCodeSame(422);

        $body = $this->jsonBody($client);

        self::assertSame(422, $body['status']);
        self::assertSame('Entidad no procesable', $body['title']);
        self::assertArrayHasKey('violations', $body);
        self::assertNotEmpty($body['violations']);

        $violation = $body['violations'][0];

        self::assertSame('name', $violation['field']);
        self::assertSame('El nombre del servicio es obligatorio.', $violation['message']);
    }

    public function testAValidationErrorFromARangeRuleAlsoCarriesViolations(): void
    {
        $client = self::createClient();
        ['user' => $user, 'organization' => $organization] = $this->registerOrganizationUser();
        $this->switchPlanOfFirstMembership($user);
        ['secret' => $secret] = $this->issueApiKey($organization, 'Escritura', [ApiScope::Write]);

        $client->request(
            'POST',
            '/api/v1/services',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json'],
            content: '{"name":"Ok","billing_interval_count":99}',
        );

        self::assertResponseStatusCodeSame(422);

        $body = $this->jsonBody($client);

        self::assertSame('billing_interval_count', $body['violations'][0]['field']);
        self::assertStringContainsString('entre 1 y 52', $body['violations'][0]['message']);
    }

    public function testProposalMatchReasonsTravelAsStructuredObjects(): void
    {
        $client = self::createClient();
        ['organization' => $organization] = $this->registerOrganizationUser();
        ['secret' => $secret] = $this->issueApiKey($organization);

        // Las razones del emparejamiento son la explicación que el integrador
        // enseña a su usuario: «coincide el dominio del remitente (+20)». Si
        // viajaran como texto, llegarían como «Array» y la explicación se
        // perdería por el camino.
        $discovery = new Discovery(
            organizationId: $organization->getId(),
            type: DiscoveryType::NEW_SERVICE,
            detectedAt: new DateTimeImmutable('2026-10-04 09:00:00'),
            proposedData: [
                'providerName' => 'OVH',
                'serviceName' => 'OVH',
                'amountMinor' => 2990,
                'currency' => 'EUR',
                'billingPeriod' => 'monthly',
                'documentType' => 'invoice',
            ],
        );
        $discovery->applyMatch(60, [
            ['signal' => 'Dominio del remitente', 'weight' => 20, 'detail' => 'ovh.com'],
            ['signal' => 'Nombre del servicio', 'weight' => 20],
        ]);
        self::getContainer()->get(DiscoveryRepositoryInterface::class)->save($discovery);

        $this->apiGet($client, '/api/v1/proposals', $secret);
        self::assertResponseIsSuccessful();

        $body = $this->jsonBody($client);
        $reasons = $body['data'][0]['match_reasons'] ?? null;

        self::assertIsArray($reasons);
        self::assertCount(2, $reasons);
        self::assertSame('Dominio del remitente', $reasons[0]['signal']);
        self::assertSame(20, $reasons[0]['weight']);
        self::assertSame('ovh.com', $reasons[0]['detail']);
        self::assertArrayNotHasKey('detail', $reasons[1]);
    }

    public function testAMissingResourceIs404ProblemJson(): void
    {
        $client = self::createClient();
        ['organization' => $organization] = $this->registerOrganizationUser();
        ['secret' => $secret] = $this->issueApiKey($organization);

        $this->apiGet($client, '/api/v1/services/019a0000-0000-7000-8000-000000000999', $secret);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('content-type'),
        );

        $body = $this->jsonBody($client);

        self::assertSame(404, $body['status']);
        self::assertArrayHasKey('type', $body);
        self::assertArrayHasKey('instance', $body);
    }
}
