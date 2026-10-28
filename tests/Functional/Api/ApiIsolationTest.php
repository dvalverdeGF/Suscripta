<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\Domain\Enum\ApiScope;
use App\Tests\Support\Api\IssuesApiKeys;
use App\Tests\Support\Identity\SwitchesPlan;

use const JSON_THROW_ON_ERROR;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Aislamiento entre organizaciones (Fase 18).
 *
 * ## Lo que está en juego
 *
 * Es el test más importante de la API. Una clave da acceso a **una**
 * organización; si una consulta se dejara sin filtrar, un integrador vería
 * las suscripciones de otro cliente. En un SaaS eso no es un bug, es un
 * incidente de seguridad con aviso a los afectados.
 *
 * ## Cómo se prueba
 *
 * Se montan dos organizaciones con datos distintos, se emite una clave para
 * cada una y se comprueba que cada clave ve **solo lo suyo**: ni un servicio
 * de más en el listado, ni un `200` al pedir por identificador un recurso
 * ajeno (tiene que ser `404`, porque decir `403` confirmaría que existe).
 */
final class ApiIsolationTest extends WebTestCase
{
    use IssuesApiKeys;
    use SwitchesPlan;

    public function testAKeyOnlySeesItsOwnOrganizationServices(): void
    {
        $client = self::createClient();

        ['user' => $firstUser, 'organization' => $first] = $this->registerOrganizationUser('primera@example.com');
        ['user' => $secondUser, 'organization' => $second] = $this->registerOrganizationUser('segunda@example.com');
        $this->switchPlanOfFirstMembership($firstUser);
        $this->switchPlanOfFirstMembership($secondUser);

        ['secret' => $firstSecret] = $this->issueApiKey($first, 'Primera', [ApiScope::Write]);
        ['secret' => $secondSecret] = $this->issueApiKey($second, 'Segunda', [ApiScope::Write]);

        $this->createService($client, $firstSecret, 'Solo de la primera');
        self::assertResponseStatusCodeSame(201);
        $firstServiceId = $this->jsonBody($client)['data']['id'];

        $this->createService($client, $secondSecret, 'Solo de la segunda');
        self::assertResponseStatusCodeSame(201);

        // La primera solo ve el suyo.
        $this->apiGet($client, '/api/v1/services', $firstSecret);
        self::assertResponseIsSuccessful();

        $names = array_column($this->jsonBody($client)['data'], 'name');

        self::assertSame(['Solo de la primera'], $names);

        // Y no puede pedir el de la segunda por identificador.
        $this->apiGet($client, '/api/v1/services/'.$this->serviceIdOfSecond($client, $secondSecret), $firstSecret);
        self::assertResponseStatusCodeSame(404);

        self::assertIsString($firstServiceId);
    }

    /**
     * Identificador del único servicio de la segunda organización.
     */
    private function serviceIdOfSecond(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $secret): string
    {
        $this->apiGet($client, '/api/v1/services', $secret);
        self::assertResponseIsSuccessful();

        $data = $this->jsonBody($client)['data'];
        self::assertCount(1, $data);

        return (string) $data[0]['id'];
    }

    private function createService(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $secret, string $name): void
    {
        $client->request(
            'POST',
            '/api/v1/services',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => $name], JSON_THROW_ON_ERROR),
        );
    }
}
