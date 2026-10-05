<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Notifications\Domain\Enum\AlertStatus;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Repository\AlertRepositoryInterface;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * El comando es el trabajo periódico del producto: si no recorre bien las
 * organizaciones, hay clientes que no reciben avisos y nadie se entera.
 *
 * Se prueba contra la base de datos real porque lo que se verifica es
 * precisamente el contexto de tenant: cada organización debe ver solo lo suyo.
 */
final class GenerateAlertsCommandTest extends KernelTestCase
{
    private User $user;
    private Uuid $organizationId;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $this->organizationId = $this->organizationOf($this->user);
    }

    public function testItGeneratesAnAlertForAnUpcomingCharge(): void
    {
        $this->service('OVH VPS', 2990, BillingPeriod::MONTHLY, '2026-10-08');

        $tester = $this->executeAlertsCommand();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Avisos generados', $tester->getDisplay());

        $alerts = $this->alerts()->findForOrganization(AlertStatus::OPEN);

        self::assertCount(1, $alerts);
        self::assertSame(AlertType::UPCOMING_CHARGE, $alerts[0]->getType());
    }

    public function testRunningItTwiceDoesNotDuplicateAlerts(): void
    {
        $this->service('OVH VPS', 2990, BillingPeriod::MONTHLY, '2026-10-08');

        $this->executeAlertsCommand();
        $this->executeAlertsCommand();

        self::assertCount(1, $this->alerts()->findForOrganization(AlertStatus::OPEN));
    }

    public function testItDoesNotReopenADismissedAlert(): void
    {
        $this->service('OVH VPS', 2990, BillingPeriod::MONTHLY, '2026-10-08');

        $this->executeAlertsCommand();

        $alert = $this->alerts()->findForOrganization(AlertStatus::OPEN)[0];
        $alert->dismiss($this->user->getId(), new DateTimeImmutable('2026-10-05 09:00:00'));
        $this->alerts()->save($alert);

        $this->executeAlertsCommand();

        self::assertSame([], $this->alerts()->findForOrganization(AlertStatus::OPEN));
        self::assertCount(1, $this->alerts()->findForOrganization(AlertStatus::DISMISSED));
    }

    public function testItResolvesAnAlertWhoseChargeHasPassed(): void
    {
        $service = $this->service('OVH VPS', 2990, BillingPeriod::MONTHLY, '2026-10-08');

        $this->executeAlertsCommand();
        self::assertCount(1, $this->alerts()->findForOrganization(AlertStatus::OPEN));

        $this->tenant()->runAs($this->organizationId, static function () use ($service): void {
            $service->setNextChargeAt(new DateTimeImmutable('2026-12-08'));
            self::getContainer()->get(ServiceRepositoryInterface::class)->save($service);
        });

        $this->executeAlertsCommand();

        self::assertSame([], $this->alerts()->findForOrganization(AlertStatus::OPEN));
        self::assertCount(1, $this->alerts()->findForOrganization(AlertStatus::RESOLVED));
    }

    public function testItOnlyProcessesTheRequestedOrganization(): void
    {
        $this->service('OVH VPS', 2990, BillingPeriod::MONTHLY, '2026-10-08');

        $other = self::getContainer()->get(RegisterUser::class)('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');
        $otherOrganizationId = $this->organizationOf($other);
        $this->serviceFor($otherOrganizationId, 'Scaleway', 1990, '2026-10-09');

        $tester = $this->executeAlertsCommand(['--organization' => $otherOrganizationId->toRfc4122()]);

        self::assertSame(0, $tester->getStatusCode());

        // Solo la organización pedida. Si el filtro de tenant no se publicase al
        // cambiar de contexto, el comando generaría avisos cruzados: los dos
        // servicios acabarían en la misma organización.
        $alerts = $this->alerts()->findForOrganization(AlertStatus::OPEN);

        self::assertCount(1, $alerts);
        self::assertSame($otherOrganizationId->toRfc4122(), $alerts[0]->getOrganizationId()->toRfc4122());
    }

    public function testAnUnknownOrganizationIsReported(): void
    {
        $tester = $this->executeAlertsCommand(['--organization' => Uuid::v7()->toRfc4122()]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No hay ninguna organización', $tester->getDisplay());
    }

    public function testNoNotifySkipsTheDelivery(): void
    {
        $this->service('OVH VPS', 2990, BillingPeriod::MONTHLY, '2026-10-08');

        $tester = $this->executeAlertsCommand(['--no-notify' => true]);

        self::assertStringNotContainsString('Notificaciones:', $tester->getDisplay());
        self::assertCount(1, $this->alerts()->findForOrganization(AlertStatus::OPEN));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeAlertsCommand(array $input = []): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:alerts:generate'));
        $tester->execute($input);

        return $tester;
    }

    private function alerts(): AlertRepositoryInterface
    {
        return self::getContainer()->get(AlertRepositoryInterface::class);
    }

    private function tenant(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function organizationOf(User $user): Uuid
    {
        $organizations = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($user);

        self::assertNotSame([], $organizations);

        return $organizations[0]['organization']->getId();
    }

    private function service(string $name, int $amountMinor, BillingPeriod $period, string $nextChargeAt): Service
    {
        return $this->serviceFor($this->organizationId, $name, $amountMinor, $nextChargeAt, $period);
    }

    private function serviceFor(
        Uuid $organizationId,
        string $name,
        int $amountMinor,
        string $nextChargeAt,
        BillingPeriod $period = BillingPeriod::MONTHLY,
    ): Service {
        return $this->tenant()->runAs($organizationId, static function () use ($organizationId, $name, $amountMinor, $period, $nextChargeAt): Service {
            $service = new Service(
                organizationId: $organizationId,
                name: $name,
                currency: Currency::EUR,
                billingPeriod: $period,
                source: ServiceSource::MANUAL,
            );
            $service->changePrice(Money::of($amountMinor, Currency::EUR), new DateTimeImmutable('2026-01-01'));
            $service->setNextChargeAt(new DateTimeImmutable($nextChargeAt));

            self::getContainer()->get(ServiceRepositoryInterface::class)->save($service);

            return $service;
        });
    }
}
