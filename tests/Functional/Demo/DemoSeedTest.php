<?php

declare(strict_types=1);

namespace App\Tests\Functional\Demo;

use App\Ai\Domain\Repository\AiUsageRepositoryInterface;
use App\Demo\Application\DemoAccount;
use App\Demo\Application\DemoSeedResult;
use App\Demo\Application\SeedDemoTenant;
use App\Demo\UI\Twig\DemoAccountRuntime;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Services\Domain\Repository\ServiceRepositoryInterface;

use function array_map;
use function count;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * La siembra de demostración (D-67).
 *
 * ## Lo que esta prueba fija
 *
 * 1. La siembra **deja la cuenta lista para entrar**: existe, está verificada
 *    y tiene la guía de bienvenida vista, que es lo que hace que el panel se
 *    vea en vez de la guía.
 * 2. La siembra es **idempotente**: repetirla no duplica servicios ni consumo.
 * 3. La siembra produce datos **variados**: no son todos servicios iguales,
 *    porque un panel donde todo está en el mismo estado no enseña nada.
 * 4. Las credenciales **no se enseñan fuera del desarrollo**.
 */
final class DemoSeedTest extends KernelTestCase
{
    public function testTheSeedCreatesTheAccountAndTheTenant(): void
    {
        $result = $this->seed();

        self::assertTrue($result->userCreated);
        self::assertNotSame('', $result->email);
        self::assertNotSame('', $result->password);
        self::assertGreaterThan(0, $result->servicesCreated);
        self::assertGreaterThan(0, $result->alertsCreated);
        self::assertGreaterThan(0, $result->aiCalls);

        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail($result->email);

        self::assertNotNull($user);
        self::assertTrue($user->isVerified(), 'La cuenta de demostración está verificada: no hay correo que esperar.');
        self::assertTrue($user->isOnboardingCompleted(), 'La cuenta de demostración entra directa al panel.');
    }

    public function testTheSeedIsIdempotent(): void
    {
        $first = $this->seed();
        $second = $this->seed();

        self::assertGreaterThan(0, $first->servicesCreated);
        self::assertSame(0, $second->servicesCreated, 'La segunda siembra no crea servicios nuevos.');
        self::assertSame(0, $second->aiCalls, 'La segunda siembra no vuelve a registrar consumo.');
        self::assertFalse($second->userCreated, 'La segunda siembra no crea otra cuenta.');
    }

    public function testTheSeededInventoryIsVaried(): void
    {
        $result = $this->seed();

        $services = self::getContainer()->get(ServiceRepositoryInterface::class);

        $statuses = [];
        $currencies = [];

        foreach ($services->findAllForOrganization($this->organizationId($result)) as $service) {
            $statuses[$service->getStatus()->value] = true;
            $currencies[$service->getCurrency()->value] = true;
        }

        // Un panel donde todo está activo y en euros no enseña nada del
        // producto: la demo tiene que incluir lo pausado, lo cancelado y lo
        // que está en otra moneda.
        self::assertGreaterThan(1, count($statuses), 'La demostración incluye servicios en varios estados.');
        self::assertGreaterThan(1, count($currencies), 'La demostración incluye servicios en varias monedas.');

        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail($result->email);
        self::assertNotNull($user);

        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($user);

        self::assertNotEmpty($memberships);

        // La cuenta de demostración tiene dos organizaciones. La sembrada es
        // la personal, que es la que el sistema elige como activa; la otra
        // existe para poder cambiar y ver el aislamiento por tenant.
        $names = array_map(
            static fn (array $membership): string => $membership['organization']->getName(),
            $memberships,
        );

        self::assertContains($result->organizationName, $names);
        self::assertContains('Acme Consulting', $names);
        self::assertCount(2, $memberships);
    }

    public function testTheAiConsumptionIsRegisteredForTheOrganization(): void
    {
        $result = $this->seed();

        $usage = self::getContainer()->get(AiUsageRepositoryInterface::class);

        self::assertCount($result->aiCalls, $usage->findForOrganization($this->organizationId($result)));
    }

    public function testTheCredentialsAreNotShownOutsideDevelopment(): void
    {
        // El entorno de las pruebas no es `dev`: la función de Twig devuelve
        // `null` y la pantalla de login no pinta el bloque. Es lo que evita
        // que una instalación real enseñe la credencial de la demo.
        $account = self::getContainer()->get(DemoAccountRuntime::class)->account();

        self::assertNull($account);
    }

    public function testTheDemoAccountComesFromTheConfiguration(): void
    {
        $account = self::getContainer()->get(DemoAccount::class);

        self::assertNotSame('', $account->email);
        self::assertNotSame('', $account->password);
    }

    private function seed(): DemoSeedResult
    {
        return (self::getContainer()->get(SeedDemoTenant::class))();
    }

    /**
     * La organización en la que se ha sembrado.
     *
     * Se busca por el nombre que devuelve la siembra y no por «la primera»,
     * porque la cuenta de demostración tiene dos organizaciones y el orden en
     * que se crean no es un criterio fiable.
     */
    private function organizationId(DemoSeedResult $result): Uuid
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail($result->email);
        self::assertNotNull($user);

        foreach (self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($user) as $membership) {
            if ($membership['organization']->getName() === $result->organizationName) {
                return $membership['organization']->getId();
            }
        }

        self::fail(sprintf('No se ha encontrado la organización «%s».', $result->organizationName));
    }
}
