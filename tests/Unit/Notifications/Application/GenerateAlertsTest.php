<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Application;

use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Notifications\Application\Dto\AlertGenerationResult;
use App\Notifications\Application\GenerateAlerts;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertStatus;
use App\Notifications\Domain\Enum\AlertType;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Services\Domain\Service\ServiceCostCalculator;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Notifications\InMemoryAlertRepository;

use function array_map;
use function array_values;

use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * El generador es el corazón de la Fase 6 y su propiedad más importante es la
 * **idempotencia**: se ejecuta cada hora, así que si creara avisos duplicados el
 * usuario recibiría el mismo correo una y otra vez y dejaría de leerlos.
 *
 * La segunda propiedad es que **resuelve** lo que ya no aplica. Un aviso de
 * cobro que se quedó abierto después del cobro es peor que no tener aviso.
 */
final class GenerateAlertsTest extends TestCase
{
    private const TODAY = '2026-10-05';

    private Uuid $organizationId;
    private InMemoryAlertRepository $alerts;
    private ServiceRepositoryInterface&MockObject $services;
    private DiscoveryRepositoryInterface&MockObject $discoveries;
    private AuditLoggerInterface&MockObject $auditLogger;
    private DateTimeImmutable $now;
    private int $pendingDiscoveries = 0;

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->alerts = new InMemoryAlertRepository();
        $this->services = $this->createMock(ServiceRepositoryInterface::class);
        $this->discoveries = $this->createMock(DiscoveryRepositoryInterface::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->now = new DateTimeImmutable(self::TODAY.' 09:00:00');

        $this->services->method('findForOrganization')->willReturn([]);
        $this->discoveries->method('countPending')->willReturnCallback(fn (): int => $this->pendingDiscoveries);
    }

    public function testItCreatesNothingWhenThereIsNothingToWarnAbout(): void
    {
        $result = $this->generate();

        self::assertSame(0, $result->created);
        self::assertSame(0, $result->open);
        self::assertSame([], $this->alerts->all());
    }

    public function testItWarnsAboutAChargeWithinTheLeadWindow(): void
    {
        $this->withServices($this->service(nextChargeAt: self::TODAY.' +5 days'));

        $result = $this->generate();

        self::assertSame(1, $result->created);
        self::assertSame(AlertType::UPCOMING_CHARGE, $this->alerts->all()[0]->getType());
        self::assertSame(AlertSeverity::WARNING, $this->alerts->all()[0]->getSeverity());
    }

    public function testAChargeWithinThreeDaysIsCritical(): void
    {
        $this->withServices($this->service(nextChargeAt: self::TODAY.' +2 days'));

        $this->generate();

        self::assertSame(AlertSeverity::CRITICAL, $this->alerts->all()[0]->getSeverity());
    }

    public function testItIgnoresAChargeBeyondTheLeadWindow(): void
    {
        $this->withServices($this->service(nextChargeAt: self::TODAY.' +20 days'));

        self::assertSame(0, $this->generate()->created);
    }

    public function testItIgnoresAChargeThatAlreadyHappened(): void
    {
        $this->withServices($this->service(nextChargeAt: self::TODAY.' -1 day'));

        self::assertSame(0, $this->generate()->created);
    }

    public function testItWarnsAboutARenewalWithinThirtyDays(): void
    {
        $this->withServices($this->service(renewalAt: self::TODAY.' +20 days'));

        $result = $this->generate();

        self::assertSame(1, $result->created);
        self::assertSame(AlertType::UPCOMING_RENEWAL, $this->alerts->all()[0]->getType());
    }

    public function testAnAnnualRenewalGetsSixtyDaysOfNotice(): void
    {
        $this->withServices($this->service(
            period: BillingPeriod::ANNUAL,
            renewalAt: self::TODAY.' +45 days',
        ));

        $this->generate();

        self::assertSame(AlertType::ANNUAL_RENEWAL, $this->alerts->all()[0]->getType());
    }

    public function testItWarnsAboutTheNoticeDeadline(): void
    {
        $this->withServices($this->service(
            renewalAt: self::TODAY.' +40 days',
            noticePeriodDays: 30,
        ));

        $this->generate();

        $types = $this->types();

        self::assertContains(AlertType::NOTICE_DEADLINE, $types);
    }

    public function testItWarnsAboutTheEndOfACommitment(): void
    {
        $this->withServices($this->service(commitmentEndAt: self::TODAY.' +10 days'));

        $this->generate();

        self::assertContains(AlertType::COMMITMENT_ENDING, $this->types());
    }

    public function testItWarnsAboutANoteworthyPriceIncrease(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-01'));
        $service->changePrice(Money::of(1500, Currency::EUR), new DateTimeImmutable('2026-09-01'));

        $this->withServices($service);

        $this->generate();

        self::assertContains(AlertType::PRICE_INCREASE, $this->types());
    }

    public function testItIgnoresATrivialPriceIncrease(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-01'));
        $service->changePrice(Money::of(1010, Currency::EUR), new DateTimeImmutable('2026-09-01'));

        $this->withServices($service);

        $this->generate();

        self::assertNotContains(AlertType::PRICE_INCREASE, $this->types());
    }

    public function testItWarnsAboutPendingDiscoveries(): void
    {
        $this->pendingDiscoveries = 3;

        $this->generate();

        self::assertSame(AlertType::DISCOVERY_PENDING, $this->alerts->all()[0]->getType());
        self::assertSame(AlertSeverity::INFO, $this->alerts->all()[0]->getSeverity());
    }

    public function testItIgnoresPausedServices(): void
    {
        $this->withServices($this->service(
            nextChargeAt: self::TODAY.' +2 days',
            status: ServiceStatus::PAUSED,
        ));

        self::assertSame(0, $this->generate()->created);
    }

    public function testRunningTwiceCreatesNoDuplicates(): void
    {
        $this->withServices($this->service(nextChargeAt: self::TODAY.' +5 days'));

        $first = $this->generate();
        $second = $this->generate();

        self::assertSame(1, $first->created);
        self::assertSame(0, $second->created);
        self::assertSame(1, $second->unchanged);
        self::assertCount(1, $this->alerts->all());
    }

    public function testItResolvesAnAlertThatNoLongerApplies(): void
    {
        $service = $this->service(nextChargeAt: self::TODAY.' +5 days');
        $this->withServices($service);

        $this->generate();

        // El cobro se adelanta y sale de la ventana: el aviso ya no describe la
        // realidad y debe cerrarse solo.
        $service->setNextChargeAt(new DateTimeImmutable(self::TODAY.' +40 days'));

        $result = $this->generate();

        self::assertSame(1, $result->resolved);
        self::assertSame(AlertStatus::RESOLVED, $this->alerts->all()[0]->getStatus());
        self::assertSame(0, $result->open);
    }

    public function testItDoesNotReopenADismissedAlert(): void
    {
        $this->withServices($this->service(nextChargeAt: self::TODAY.' +5 days'));

        $this->generate();

        $alert = $this->alerts->all()[0];
        $alert->dismiss(Uuid::v7(), $this->now);

        $result = $this->generate();

        self::assertSame(0, $result->created);
        self::assertSame(AlertStatus::DISMISSED, $alert->getStatus());
    }

    public function testItDoesNotResolveADismissedAlert(): void
    {
        $service = $this->service(nextChargeAt: self::TODAY.' +5 days');
        $this->withServices($service);

        $this->generate();

        $alert = $this->alerts->all()[0];
        $alert->dismiss(Uuid::v7(), $this->now);

        $service->setNextChargeAt(new DateTimeImmutable(self::TODAY.' +40 days'));

        $result = $this->generate();

        self::assertSame(0, $result->resolved);
        self::assertSame(AlertStatus::DISMISSED, $alert->getStatus());
    }

    public function testItAuditsOnlyWhenSomethingChanged(): void
    {
        $this->withServices($this->service(nextChargeAt: self::TODAY.' +5 days'));

        $this->auditLogger->expects(self::once())->method('log');

        $this->generate();
        $this->generate();
    }

    public function testItFlushesOnlyWhenSomethingChanged(): void
    {
        $this->withServices($this->service(nextChargeAt: self::TODAY.' +5 days'));

        $this->generate();
        $this->generate();

        self::assertSame(1, $this->alerts->flushCount);
    }

    public function testTheDedupKeyIncludesTheTargetDate(): void
    {
        $service = $this->service(nextChargeAt: self::TODAY.' +5 days');
        $this->withServices($service);

        $this->generate();

        $expected = Alert::buildDedupKey(
            AlertType::UPCOMING_CHARGE,
            $service->getId(),
            new DateTimeImmutable(self::TODAY.' +5 days'),
        );

        self::assertSame($expected, $this->alerts->all()[0]->getDedupKey());
    }

    private function generate(): AlertGenerationResult
    {
        $generator = new GenerateAlerts(
            services: $this->services,
            alerts: $this->alerts,
            discoveries: $this->discoveries,
            costs: new ServiceCostCalculator(),
            tenantContext: $this->tenantContext(),
            auditLogger: $this->auditLogger,
            clock: new Clock(new MockClock($this->now)),
        );

        return $generator($this->now);
    }

    private function tenantContext(): TenantContext
    {
        $context = new TenantContext();
        $context->setOrganizationId($this->organizationId);

        return $context;
    }

    /**
     * @return list<AlertType>
     */
    private function types(): array
    {
        return array_map(static fn (Alert $alert): AlertType => $alert->getType(), $this->alerts->all());
    }

    private function withServices(Service ...$services): void
    {
        $this->services = $this->createMock(ServiceRepositoryInterface::class);
        $this->services->method('findForOrganization')->willReturn(array_values($services));
    }

    private function service(
        ?string $nextChargeAt = null,
        ?string $renewalAt = null,
        ?string $commitmentEndAt = null,
        ?int $noticePeriodDays = null,
        BillingPeriod $period = BillingPeriod::MONTHLY,
        ServiceStatus $status = ServiceStatus::ACTIVE,
    ): Service {
        $service = new Service(
            organizationId: $this->organizationId,
            name: 'OVH VPS',
            currency: Currency::EUR,
            billingPeriod: $period,
            source: ServiceSource::MANUAL,
            status: $status,
        );

        $service->changePrice(Money::of(2990, Currency::EUR), new DateTimeImmutable('2026-01-01'));

        if (null !== $nextChargeAt) {
            $service->setNextChargeAt(new DateTimeImmutable($nextChargeAt));
        }

        if (null !== $renewalAt) {
            $service->setRenewalAt(new DateTimeImmutable($renewalAt));
            $service->setAutoRenews(true);
        }

        if (null !== $commitmentEndAt) {
            $service->setCommitmentEndAt(new DateTimeImmutable($commitmentEndAt));
        }

        if (null !== $noticePeriodDays) {
            $service->setNoticePeriodDays($noticePeriodDays);
        }

        return $service;
    }
}
