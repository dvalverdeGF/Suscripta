<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application;

use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Enum\OrganizationPlan;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Entitlements\Capability;
use App\Shared\Application\Entitlements\EntitlementSettings;
use App\Shared\Application\Entitlements\EntitlementUsageProviderInterface;
use App\Shared\Application\Entitlements\QuotaGuard;
use App\Shared\Application\OrganizationSettingsReader;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Exception\QuotaExceededException;
use App\Shared\Infrastructure\Entitlements\PlanEntitlementChecker;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function sprintf;

use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * La tabla de decisiones del guard de cuotas (Fase 17).
 *
 * ## Por qué un test unitario y no solo el funcional
 *
 * El test funcional (`tests/Functional/Identity/PlanTest.php`) es el que
 * detectó el defecto D-70: el localizador de proveedores estaba indexado por
 * el nombre de la clase y el guard no encontraba ningún contador, de modo que
 * **ningún límite se aplicaba**. Ese fallo era del **cableado del
 * contenedor**, no de la lógica, así que aquí se comprueba la lógica con
 * dobles y allí se comprueba el cableado con el contenedor de verdad.
 *
 * ## La tabla
 *
 * - Con hueco (`2 de 3`): dentro.
 * - Agotado (`3 de 3`): fuera, y la excepción lo dice.
 * - Sin techo (plan `pro`): siempre dentro.
 * - Capacidad que el plan no ofrece (IA en `free`): bloqueada, y ni se
 *   cuenta.
 */
#[CoversClass(QuotaGuard::class)]
final class QuotaGuardTest extends TestCase
{
    public function testItAllowsWhileThereIsRoom(): void
    {
        $guard = $this->guard(OrganizationPlan::FREE, ['services' => 2]);

        self::assertTrue($guard->isWithinQuota(Capability::SERVICES));

        $guard->assertWithinQuota(Capability::SERVICES);

        $status = $guard->status(Capability::SERVICES);

        self::assertSame(2, $status->usage);
        self::assertSame(3, $status->limit);
        self::assertSame(1, $status->remaining());
        self::assertFalse($status->isExhausted());
    }

    public function testItBlocksWhenTheLimitIsReached(): void
    {
        $guard = $this->guard(OrganizationPlan::FREE, ['services' => 3]);

        self::assertFalse($guard->isWithinQuota(Capability::SERVICES));

        $this->expectException(QuotaExceededException::class);

        $guard->assertWithinQuota(Capability::SERVICES);
    }

    public function testTheExceptionSaysWhatWasExceeded(): void
    {
        $guard = $this->guard(OrganizationPlan::FREE, ['services' => 3]);

        try {
            $guard->assertWithinQuota(Capability::SERVICES);
            self::fail('El límite agotado debería lanzar la excepción.');
        } catch (QuotaExceededException $exception) {
            self::assertSame(Capability::SERVICES, $exception->capability);
            self::assertSame(3, $exception->usage);
            self::assertSame(3, $exception->limit);
            self::assertSame('Gratis', $exception->planLabel);
        }
    }

    public function testACapabilityThePlanDoesNotOfferIsBlockedAndNotCounted(): void
    {
        $guard = $this->guard(OrganizationPlan::FREE, ['services' => 0]);

        self::assertFalse($guard->allows(Capability::AI));
        self::assertFalse($guard->isWithinQuota(Capability::AI));

        $status = $guard->status(Capability::AI);

        self::assertTrue($status->isBlocked());
        self::assertSame(0, $status->usage);

        $this->expectException(QuotaExceededException::class);

        $guard->assertWithinQuota(Capability::AI);
    }

    public function testACapabilityWithoutCeilingIsNeverExhausted(): void
    {
        $guard = $this->guard(OrganizationPlan::PRO, ['services' => 99]);

        self::assertTrue($guard->isWithinQuota(Capability::SERVICES));
        self::assertNull($guard->limit(Capability::SERVICES));

        $status = $guard->status(Capability::SERVICES);

        self::assertTrue($status->isUnlimited());
        self::assertNull($status->remaining());
        self::assertNull($status->usedPercent());

        $guard->assertWithinQuota(Capability::SERVICES);
    }

    public function testItKnowsTheNameOfThePlan(): void
    {
        self::assertSame('Gratis', $this->guard(OrganizationPlan::FREE, [])->planLabel());
        self::assertSame('Profesional', $this->guard(OrganizationPlan::PRO, [])->planLabel());
    }

    public function testItAsksForAProviderWhenThereIsNone(): void
    {
        $guard = $this->guard(OrganizationPlan::FREE, []);

        $this->expectException(LogicException::class);

        $guard->usage(Capability::SERVICES);
    }

    /**
     * Monta un guard con la organización activa, un plan y sus contadores.
     *
     * @param array<string, int> $usageByCapability contadores por capacidad
     */
    private function guard(OrganizationPlan $plan, array $usageByCapability): QuotaGuard
    {
        $organization = new Organization('Acme', 'acme');
        $organization->changePlan($plan);

        $repositories = $this->createMock(OrganizationRepositoryInterface::class);
        $repositories->method('find')->willReturn($organization);

        $context = new TenantContext();
        $context->setOrganizationId($organization->getId());

        $settings = EntitlementSettings::fromArray([
            'capabilities' => [
                Capability::SERVICES => ['label' => 'Servicios', 'type' => 'limit', 'unit' => 'servicios'],
                Capability::EMAIL_ACCOUNTS => ['label' => 'Cuentas de correo', 'type' => 'limit', 'unit' => 'buzones'],
                Capability::DOCUMENTS => ['label' => 'Documentos', 'type' => 'limit', 'unit' => 'documentos'],
                Capability::AI => ['label' => 'Inteligencia artificial', 'type' => 'flag'],
                Capability::AI_BUDGET_MINOR => ['label' => 'Presupuesto mensual de IA', 'type' => 'limit', 'unit' => 'céntimos'],
            ],
            'plans' => [
                'free' => [
                    'label' => 'Gratis',
                    'limits' => [
                        Capability::SERVICES => 3,
                        Capability::EMAIL_ACCOUNTS => 1,
                        Capability::DOCUMENTS => 20,
                        Capability::AI => false,
                        Capability::AI_BUDGET_MINOR => 0,
                    ],
                ],
                'pro' => [
                    'label' => 'Profesional',
                    'limits' => [
                        Capability::SERVICES => null,
                        Capability::EMAIL_ACCOUNTS => 5,
                        Capability::DOCUMENTS => 500,
                        Capability::AI => true,
                        Capability::AI_BUDGET_MINOR => 10000,
                    ],
                ],
            ],
        ]);

        $organizations = new OrganizationSettingsReader($repositories, $context);

        return new QuotaGuard(
            new PlanEntitlementChecker($settings, $organizations),
            $settings,
            $organizations,
            $this->providers($usageByCapability),
        );
    }

    /**
     * Localizador de contadores de uso, con las claves de capacidad.
     *
     * @param array<string, int> $usageByCapability
     *
     * @return ServiceProviderInterface<EntitlementUsageProviderInterface>
     */
    private function providers(array $usageByCapability): ServiceProviderInterface
    {
        return new class($usageByCapability) implements ServiceProviderInterface {
            /** @var array<string, EntitlementUsageProviderInterface> */
            private array $providers;

            /**
             * @param array<string, int> $usageByCapability
             */
            public function __construct(array $usageByCapability)
            {
                foreach ($usageByCapability as $capability => $count) {
                    $this->providers[$capability] = new class($capability, $count) implements EntitlementUsageProviderInterface {
                        public function __construct(
                            private string $capability,
                            private int $count,
                        ) {
                        }

                        public function capability(): string
                        {
                            return $this->capability;
                        }

                        public function usage(): int
                        {
                            return $this->count;
                        }
                    };
                }
            }

            public function get(string $id): mixed
            {
                if (!$this->has($id)) {
                    throw new RuntimeException(sprintf('No hay ningún proveedor para "%s".', $id));
                }

                return $this->providers[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->providers[$id]);
            }

            public function getProvidedServices(): array
            {
                $services = [];

                foreach ($this->providers as $capability => $provider) {
                    $services[$capability] = $provider::class;
                }

                return $services;
            }
        };
    }
}
