<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Identity\Application\RegisterUser;
use App\Shared\Application\RateLimit\RateLimitGuard;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Puerta de frecuencia (Fase 16).
 *
 * Se prueba con el limitador **real** de Symfony, no con un doble: un doble
 * probaría el test, no el código. Por eso el entorno de test trae un
 * limitador `test_gate` con tres huecos y una clave distinta en cada test
 * (el almacenamiento de contadores es un pool de cache en disco, y los
 * contadores sobreviven entre ejecuciones).
 */
final class RateLimitTest extends WebTestCase
{
    public function testTheGuardAcceptsUntilTheLimitIsReached(): void
    {
        $guard = self::getContainer()->get(RateLimitGuard::class);
        $key = $this->uniqueKey();

        self::assertTrue($guard->acquire('test_gate', $key));
        self::assertTrue($guard->acquire('test_gate', $key));
        self::assertTrue($guard->acquire('test_gate', $key));
        self::assertFalse($guard->acquire('test_gate', $key));
        self::assertFalse($guard->acquire('test_gate', $key));
    }

    public function testTheGuardCountsEachKeySeparately(): void
    {
        $guard = self::getContainer()->get(RateLimitGuard::class);

        $first = $this->uniqueKey();
        $second = $this->uniqueKey();

        self::assertTrue($guard->acquire('test_gate', $first));
        self::assertTrue($guard->acquire('test_gate', $first));
        self::assertTrue($guard->acquire('test_gate', $first));
        self::assertFalse($guard->acquire('test_gate', $first));

        // La otra clave tiene su propia ventana: un cliente pesado no
        // puede dejar sin servicio a los demás.
        self::assertTrue($guard->acquire('test_gate', $second));
    }

    public function testTheGuardCanConsumeMoreThanOneTokenAtATime(): void
    {
        $guard = self::getContainer()->get(RateLimitGuard::class);
        $key = $this->uniqueKey();

        self::assertTrue($guard->acquire('test_gate', $key, 2));
        self::assertFalse($guard->acquire('test_gate', $key, 2));
    }

    public function testTheGuardFailsLoudlyWhenTheLimiterDoesNotExist(): void
    {
        $guard = self::getContainer()->get(RateLimitGuard::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('El limitador "no_existe" no esta configurado.');

        $guard->acquire('no_existe', $this->uniqueKey());
    }

    public function testTheExportIsLimitedAndAnswersTooManyRequests(): void
    {
        $client = $this->clientWithUser();

        // El límite de producción es de 5 exportaciones por minuto y
        // organización.
        for ($i = 0; $i < 5; ++$i) {
            $client->request('GET', '/privacy/export.json');

            self::assertResponseIsSuccessful();
        }

        $client->request('GET', '/privacy/export.json');

        self::assertResponseStatusCodeSame(429);
    }

    public function testTheLimitIsPerOrganizationAndNotGlobal(): void
    {
        $client = self::createClient();

        $registerUser = self::getContainer()->get(RegisterUser::class);

        $ada = $registerUser('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $client->loginUser($ada);

        for ($i = 0; $i < 5; ++$i) {
            $client->request('GET', '/privacy/export.json');
        }

        $client->request('GET', '/privacy/export.json');
        self::assertResponseStatusCodeSame(429);

        // Otra organización no hereda el límite de la anterior: cada usuario
        // registrado trae la suya propia.
        $grace = $registerUser('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');
        $client->loginUser($grace);

        $client->request('GET', '/privacy/export.json');

        self::assertResponseIsSuccessful();
    }

    private function clientWithUser(string $email = 'ada@example.com'): KernelBrowser
    {
        $client = self::createClient();

        $registerUser = self::getContainer()->get(RegisterUser::class);
        $user = $registerUser($email, 'Sup3rSecret!2026', 'Ada Lovelace');
        $client->loginUser($user);

        return $client;
    }

    /**
     * Clave única por test: los contadores viven en un pool de cache en disco
     * y sobreviven entre ejecuciones, así que una clave fija haría el test
     * dependiente del orden de ejecución.
     */
    private function uniqueKey(): string
    {
        return Uuid::v4()->toRfc4122();
    }
}
