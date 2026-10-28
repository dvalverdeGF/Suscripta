<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Shared\Application\RateLimit\RateLimitGuard;
use App\Tests\Support\Api\IssuesApiKeys;

use function bin2hex;
use function random_bytes;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El límite de frecuencia de la API (Fase 18).
 *
 * ## Por qué se prueba con el limitador real
 *
 * Un doble probaría el test, no el código. El límite de la API es de 300
 * peticiones por minuto **por clave**: la clave es la unidad real de consumo
 * (varias pueden salir del mismo servidor detrás de un NAT) y es la que se
 * puede explicar al integrador.
 *
 * ## Los contadores sobreviven entre ejecuciones
 *
 * Viven en un pool de cache en disco, así que cada prueba usa una clave
 * distinta: si se reutilizara, la segunda ejecución empezaría con la ventana a
 * medias y el test fallaría sin que hubiera cambiado nada.
 */
final class ApiRateLimitTest extends WebTestCase
{
    use IssuesApiKeys;

    private const LIMIT = 300;

    public function testTheApiLimitIsReachedAtThreeHundred(): void
    {
        $guard = self::getContainer()->get(RateLimitGuard::class);
        $key = 'api-test-'.bin2hex(random_bytes(8));

        for ($i = 1; $i <= self::LIMIT; ++$i) {
            self::assertTrue($guard->acquire('api', $key), 'Se cortó antes de la petición '.$i.'.');
        }

        self::assertFalse($guard->acquire('api', $key));
    }

    public function testEachApiKeyHasItsOwnWindow(): void
    {
        $guard = self::getContainer()->get(RateLimitGuard::class);
        $first = 'api-test-'.bin2hex(random_bytes(8));
        $second = 'api-test-'.bin2hex(random_bytes(8));

        for ($i = 1; $i <= self::LIMIT; ++$i) {
            $guard->acquire('api', $first);
        }

        self::assertFalse($guard->acquire('api', $first));

        // La otra clave no paga el consumo de la primera: un integrador
        // pesado no puede dejar sin servicio a los demás.
        self::assertTrue($guard->acquire('api', $second));
    }

    public function testAResponseAdvertisesTheRemainingQuota(): void
    {
        $client = self::createClient();
        ['organization' => $organization] = $this->registerOrganizationUser();
        ['secret' => $secret] = $this->issueApiKey($organization);

        $this->apiGet($client, '/api/v1/services', $secret);
        self::assertResponseIsSuccessful();

        $response = $client->getResponse();

        // Las cabeceras permiten al integrador adaptarse antes de comerse un
        // 429: un sincronizador que ve que le quedan pocas espera a que se
        // repongan en vez de reintentar a lo loco.
        self::assertTrue($response->headers->has('X-RateLimit-Limit'));
        self::assertTrue($response->headers->has('X-RateLimit-Remaining'));
        self::assertSame((string) self::LIMIT, $response->headers->get('X-RateLimit-Limit'));
    }
}
