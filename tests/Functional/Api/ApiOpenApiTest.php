<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use function is_array;
use function json_decode;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El contrato OpenAPI de la API (Fase 18).
 *
 * ## Por qué importa que el documento exista y sea válido
 *
 * `docs/api/` cuenta la API en prosa, pero un integrador que genera su cliente
 * (o que lo escribe con ayuda de un asistente) lee `/api/doc.json`. Si el
 * documento no se genera, o no incluye una ruta, la integración empieza con
 * una pieza menos y el fallo aparece en su código, no en el nuestro.
 */
final class ApiOpenApiTest extends WebTestCase
{
    public function testTheOpenApiDocumentIsServed(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
    }

    public function testTheDocumentDeclaresEveryV1Endpoint(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/doc.json');
        self::assertResponseIsSuccessful();

        $document = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue(is_array($document));
        self::assertArrayHasKey('paths', $document);

        $paths = $document['paths'];

        foreach ([
            '/api/v1/services',
            '/api/v1/services/{id}',
            '/api/v1/documents',
            '/api/v1/alerts',
            '/api/v1/metrics',
            '/api/v1/plan',
            '/api/v1/proposals',
            '/api/v1/mail/accounts',
            '/api/v1/charges/upcoming',
        ] as $path) {
            self::assertArrayHasKey($path, $paths, 'Falta la ruta '.$path.' en el documento OpenAPI.');
        }
    }

    public function testTheDocumentDescribesTheSecurityScheme(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/doc.json');
        $document = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($document);
        self::assertArrayHasKey('components', $document);
        self::assertArrayHasKey('securitySchemes', $document['components']);
    }
}
