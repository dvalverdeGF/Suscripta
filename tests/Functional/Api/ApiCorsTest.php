<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El preflight de CORS (Fase 18).
 *
 * ## Por qué este test existe
 *
 * El preflight devolvía `405` y la API era inutilizable desde un navegador.
 * La causa no era el firewall, como parecía, sino el **router**: resolvía la
 * ruta antes que el listener de CORS, y como las rutas de la API solo aceptan
 * `GET` y `POST`, un `OPTIONS` moría con `405` antes de llegar al listener.
 *
 * Este test fija el comportamiento correcto para que no vuelva a pasar: si
 * alguien baja la prioridad del listener por debajo de la del router, el
 * `204` se convierte en `405` y esto se pone rojo.
 *
 * ## Los tres casos
 *
 * - Origen permitido: `204` con las cabeceras `Access-Control-*`.
 * - Sin `Origin`: `405`. No es un preflight; es un `OPTIONS` a una ruta que
 *   no lo admite.
 * - Origen no permitido: `405` también. No se contesta al preflight de un
 *   origen que no está en la lista, así que el navegador bloquea.
 */
final class ApiCorsTest extends WebTestCase
{
    private const ALLOWED = 'https://integrador.test';

    public function testAPreflightFromAnAllowedOriginIsAnswered(): void
    {
        $client = self::createClient();

        $client->request('OPTIONS', '/api/v1/services', server: [
            'HTTP_ORIGIN' => self::ALLOWED,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::ALLOWED);
        self::assertStringContainsString('GET', (string) $client->getResponse()->headers->get('Access-Control-Allow-Methods'));
        self::assertStringContainsString('Authorization', (string) $client->getResponse()->headers->get('Access-Control-Allow-Headers'));
    }

    public function testThePreflightIsAnsweredWithoutCredentials(): void
    {
        $client = self::createClient();

        // Sin clave: el preflight lo manda el navegador y no puede llevarla.
        // Si el firewall lo interceptara, el navegador recibiría un 401 y la
        // petición real no saldría nunca.
        $client->request('OPTIONS', '/api/v1/services', server: ['HTTP_ORIGIN' => self::ALLOWED]);

        self::assertResponseStatusCodeSame(204);
    }

    public function testAnOptionsWithoutOriginIsNotAPreflight(): void
    {
        $client = self::createClient();

        $client->request('OPTIONS', '/api/v1/services');

        self::assertResponseStatusCodeSame(405);
    }

    public function testAPreflightFromAnUnknownOriginIsNotAnswered(): void
    {
        $client = self::createClient();

        $client->request('OPTIONS', '/api/v1/services', server: ['HTTP_ORIGIN' => 'https://atacante.example']);

        self::assertResponseStatusCodeSame(405);
    }

    public function testARealRequestCarriesTheCorsHeadersToo(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/services', server: ['HTTP_ORIGIN' => self::ALLOWED]);

        // El 401 también lleva la cabecera: si no, el navegador no podría
        // enseñar el error al integrador, solo un «CORS error» opaco.
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::ALLOWED);
    }
}
