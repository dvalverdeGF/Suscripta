<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\ChangeOrganizationPlan;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Enum\OrganizationPlan;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Services\Application\CreateService;
use App\Services\Application\Dto\ServiceInput;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Entitlements\Capability;
use App\Shared\Application\Entitlements\QuotaGuard;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\QuotaExceededException;
use App\Shared\Domain\Repository\AuditLogRepositoryInterface;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El plan y sus límites, de punta a punta (Fase 17).
 *
 * ## Lo que esta prueba fija
 *
 * 1. El límite se aplica **en el caso de uso**: no hace falta llegar al
 *    controlador para que se respete. Es lo que permitirá que la API de la
 *    fase 18 no pueda saltarlo.
 * 2. Un plan que no ofrece la capacidad la **bloquea**, no la deja sin
 *    contar.
 * 3. **Cambiar de plan no borra nada**: los servicios siguen ahí y la
 *    organización simplemente puede añadir más.
 * 4. El cambio de plan queda **auditado**.
 */
final class PlanTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private Organization $organization;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');

        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($this->user);

        self::assertNotEmpty($memberships);

        $this->organization = $memberships[0]['organization'];

        $this->client->loginUser($this->user);
    }

    public function testTheFreePlanAllowsThreeServicesAndNoMore(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->createService(sprintf('Servicio %d', $i));
        }

        self::assertSame(3, $this->serviceCount());

        $this->expectException(QuotaExceededException::class);

        $this->createService('Servicio 4');
    }

    public function testTheQuotaStopsTheOperationInTheUseCaseAndNotInTheController(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->createService(sprintf('Servicio %d', $i));
        }

        try {
            $this->createService('Servicio 4');
            self::fail('El cuarto servicio debería haber sido rechazado por el límite del plan.');
        } catch (QuotaExceededException $exception) {
            self::assertSame(Capability::SERVICES, $exception->capability);
            self::assertSame(3, $exception->usage);
            self::assertSame(3, $exception->limit);
        }

        // El servicio rechazado **no** existe: el límite se comprueba antes de
        // crear nada, no después.
        self::assertSame(3, $this->serviceCount());
    }

    public function testTheFreePlanDoesNotOfferTheAi(): void
    {
        // El guard lee el plan de la organización **activa**: sin contexto de
        // tenant no hay organización y, por diseño, no se limita nada. Por eso
        // la pregunta se hace dentro del contexto.
        [$allowsAi, $allowsServices] = $this->inTenant(
            static fn (QuotaGuard $quota): array => [$quota->allows(Capability::AI), $quota->allows(Capability::SERVICES)],
        );

        self::assertFalse($allowsAi);
        self::assertTrue($allowsServices);
    }

    public function testTheProPlanHasNoCeilingOnServices(): void
    {
        $this->changePlan(OrganizationPlan::PRO);

        for ($i = 1; $i <= 5; ++$i) {
            $this->createService(sprintf('Servicio %d', $i));
        }

        self::assertSame(5, $this->serviceCount());

        [$allowsAi, $limit] = $this->inTenant(
            static fn (QuotaGuard $quota): array => [$quota->allows(Capability::AI), $quota->limit(Capability::SERVICES)],
        );

        self::assertTrue($allowsAi);
        self::assertNull($limit);
    }

    public function testTheFreePlanLimitsTheNumberOfServices(): void
    {
        [$limit, $usage] = $this->inTenant(
            static fn (QuotaGuard $quota): array => [$quota->limit(Capability::SERVICES), $quota->usage(Capability::SERVICES)],
        );

        self::assertSame(3, $limit);
        self::assertSame(0, $usage);
    }

    public function testChangingThePlanDoesNotDeleteWhatAlreadyExists(): void
    {
        $this->createService('Servicio que se queda');
        $this->createService('Otro servicio que se queda');

        $this->changePlan(OrganizationPlan::PRO);

        self::assertSame(2, $this->serviceCount(), 'Bajar o subir de plan no borra servicios.');
    }

    public function testThePlanChangeIsAudited(): void
    {
        $this->changePlan(OrganizationPlan::PRO);

        $entries = self::getContainer()->get(TenantContext::class)->runAs(
            $this->organization->getId(),
            static fn (): array => self::getContainer()->get(AuditLogRepositoryInterface::class)->findRecent(50),
        );

        $found = false;

        foreach ($entries as $entry) {
            if (AuditAction::ORGANIZATION_PLAN_CHANGED === $entry->getAction()) {
                $found = true;

                self::assertSame('free', $entry->getMetadata()['from'] ?? null);
                self::assertSame('pro', $entry->getMetadata()['to'] ?? null);
            }
        }

        self::assertTrue($found, 'El cambio de plan tiene que quedar en el libro de auditoría.');
    }

    public function testTheScreenShowsTheConsumptionAndThePlan(): void
    {
        $this->createService('Servicio visible');

        $this->client->request('GET', '/plan');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-main', 'Gratis');
        self::assertSelectorTextContains('.bo-main', 'Servicios');
    }

    public function testTheScreenNeedsASignedInUser(): void
    {
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/plan');

        self::assertResponseRedirects('/login');
    }

    private function createService(string $name): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);

        $tenantContext->runAs(
            $this->organization->getId(),
            static fn () => self::getContainer()->get(CreateService::class)(new ServiceInput(name: $name)),
        );
    }

    private function serviceCount(): int
    {
        return self::getContainer()->get(TenantContext::class)->runAs(
            $this->organization->getId(),
            static fn (): int => self::getContainer()->get(ServiceRepositoryInterface::class)->countAll(),
        );
    }

    private function changePlan(OrganizationPlan $plan): void
    {
        self::getContainer()->get(ChangeOrganizationPlan::class)($this->organization, $plan, ['free', 'pro']);
    }

    /**
     * Ejecuta la pregunta al guard dentro del contexto de la organización.
     *
     * @template T
     *
     * @param callable(QuotaGuard): T $question
     *
     * @return T
     */
    private function inTenant(callable $question): mixed
    {
        return self::getContainer()->get(TenantContext::class)->runAs(
            $this->organization->getId(),
            static fn (): mixed => $question(self::getContainer()->get(QuotaGuard::class)),
        );
    }
}
