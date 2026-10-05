<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dashboard\Application;

use App\Catalog\Domain\Entity\Category;
use App\Catalog\Domain\Repository\CategoryRepositoryInterface;
use App\Dashboard\Application\BuildDashboardSummary;
use App\Dashboard\Application\Dto\DashboardSummary;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Notifications\Domain\Repository\AlertRepositoryInterface;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Services\Domain\Service\ServiceCostCalculator;
use App\Services\Domain\Service\SpendEvolution;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * El panel tiene que responder a las preguntas del producto (PRODUCT.md §3) sin
 * una consulta por servicio ni por buzón. Estas pruebas fijan ambas cosas: los
 * números y el número de consultas.
 */
#[CoversClass(BuildDashboardSummary::class)]
final class BuildDashboardSummaryTest extends TestCase
{
    private const NOW = '2026-10-05 10:00:00';

    private Uuid $organizationId;

    private ServiceRepositoryInterface&MockObject $services;

    private DiscoveryRepositoryInterface&MockObject $discoveries;

    private EmailAccountRepositoryInterface&MockObject $accounts;

    private EmailMessageRepositoryInterface&MockObject $messages;

    private CategoryRepositoryInterface&MockObject $categories;

    private AlertRepositoryInterface&MockObject $alerts;

    private TenantContext $tenant;

    /** @var list<Category> */
    private array $categoryList = [];

    /** @var array<string, int> */
    private array $messageCounts = [];

    /** @var list<EmailAccount> */
    private array $accountList = [];

    private int $countByAccountCalls = 0;

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();

        $this->services = $this->createMock(ServiceRepositoryInterface::class);
        $this->discoveries = $this->createMock(DiscoveryRepositoryInterface::class);
        $this->accounts = $this->createMock(EmailAccountRepositoryInterface::class);
        $this->messages = $this->createMock(EmailMessageRepositoryInterface::class);
        $this->categories = $this->createMock(CategoryRepositoryInterface::class);
        $this->alerts = $this->createMock(AlertRepositoryInterface::class);

        $this->tenant = new TenantContext();
        $this->tenant->setOrganizationId($this->organizationId);

        $this->discoveries->method('findPending')->willReturn([]);
        $this->accounts->method('findForOrganization')->willReturnCallback(fn (): array => $this->accountList);

        // Los dobles leen de propiedades mutables en lugar de devolver un valor
        // fijo: PHPUnit resuelve los stubs por orden de registro, así que un
        // stub fijado en setUp() ganaría siempre al de la prueba concreta.
        $this->messages->method('countByAccount')->willReturnCallback(function (): array {
            ++$this->countByAccountCalls;

            return $this->messageCounts;
        });

        $this->categories->method('findVisibleForOrganization')->willReturnCallback(fn (): array => $this->categoryList);
    }

    public function testItCountsMessagesWithASingleQuery(): void
    {
        $this->services->method('findForOrganization')->willReturn([]);

        // El recuento por buzón se pide una sola vez, en bloque. Si alguien
        // vuelve a recorrer los buzones preguntando de uno en uno, esta prueba
        // lo detecta.
        $this->messages->expects(self::never())->method('countForAccount');

        $this->build();

        self::assertSame(1, $this->countByAccountCalls);
    }

    public function testItAddsUpTheMessagesOfEveryMailbox(): void
    {
        $first = new EmailAccount($this->organizationId, 'ada@example.com');
        $second = new EmailAccount($this->organizationId, 'facturas@example.com');

        $this->services->method('findForOrganization')->willReturn([]);
        $this->accountList = [$first, $second];
        $this->messageCounts = [
            $first->getId()->toRfc4122() => 12,
            $second->getId()->toRfc4122() => 30,
        ];

        $summary = $this->build();

        self::assertSame(2, $summary->connectedMailboxes);
        self::assertSame(42, $summary->indexedMessages);
    }

    public function testItBreaksTheCostDownByCategory(): void
    {
        $hosting = new Category('Hosting', 'hosting');
        $software = new Category('Software', 'software');

        $this->categoryList = [$hosting, $software];
        $this->services->method('findForOrganization')->willReturn([
            $this->service('OVH VPS', 1199, BillingPeriod::MONTHLY, category: $hosting->getId()),
            $this->service('Hetzner', 500, BillingPeriod::MONTHLY, category: $hosting->getId()),
            $this->service('GitHub', 400, BillingPeriod::MONTHLY, category: $software->getId()),
        ]);

        $summary = $this->build();

        self::assertCount(2, $summary->monthlyByCategory);
        // Ordenado de mayor a menor: hosting (16,99 €) antes que software (4,00 €).
        self::assertSame('Hosting', $summary->monthlyByCategory[0]->categoryName);
        self::assertSame(1699, $summary->monthlyByCategory[0]->singleMonthly()?->amountMinor);
        self::assertSame(2, $summary->monthlyByCategory[0]->serviceCount);
        self::assertSame('Software', $summary->monthlyByCategory[1]->categoryName);
        self::assertSame(400, $summary->monthlyByCategory[1]->singleMonthly()?->amountMinor);
    }

    public function testServicesWithoutACategoryAreGroupedInsteadOfDisappearing(): void
    {
        $this->services->method('findForOrganization')->willReturn([
            $this->service('Sin categoría', 1000, BillingPeriod::MONTHLY),
        ]);

        $summary = $this->build();

        self::assertCount(1, $summary->monthlyByCategory);
        self::assertSame('Sin categoría', $summary->monthlyByCategory[0]->categoryName);
    }

    public function testACategoryWithTwoCurrenciesIsNotSummedIntoOneNumber(): void
    {
        $hosting = new Category('Hosting', 'hosting');

        $this->categoryList = [$hosting];
        $this->services->method('findForOrganization')->willReturn([
            $this->service('OVH VPS', 1199, BillingPeriod::MONTHLY, category: $hosting->getId()),
            $this->service('Vercel', 2000, BillingPeriod::MONTHLY, Currency::USD, $hosting->getId()),
        ]);

        $summary = $this->build();

        $row = $summary->monthlyByCategory[0];

        self::assertNull($row->singleMonthly(), 'Con dos divisas no hay un único importe que mostrar.');
        self::assertSame(1199, $row->monthlyByCurrency['EUR']->amountMinor);
        self::assertSame(2000, $row->monthlyByCurrency['USD']->amountMinor);
        self::assertSame(2, $row->serviceCount);
    }

    public function testItRanksTheMostExpensiveServicesByMonthlyCost(): void
    {
        $this->services->method('findForOrganization')->willReturn([
            // 12,00 €/año = 1,00 €/mes
            $this->service('Dominio .com', 1200, BillingPeriod::ANNUAL),
            // 11,99 €/mes
            $this->service('OVH VPS', 1199, BillingPeriod::MONTHLY),
            // 4,00 €/mes
            $this->service('GitHub', 400, BillingPeriod::MONTHLY),
        ]);

        $summary = $this->build();

        self::assertSame(
            ['OVH VPS', 'GitHub', 'Dominio .com'],
            array_map(static fn (Service $service): string => $service->getName(), $summary->topServices),
        );
    }

    public function testPausedServicesDoNotAppearInTheRankingOrTheBreakdown(): void
    {
        $paused = $this->service('Servicio en pausa', 5000, BillingPeriod::MONTHLY);
        $paused->pause();

        $this->services->method('findForOrganization')->willReturn([
            $this->service('OVH VPS', 1199, BillingPeriod::MONTHLY),
            $paused,
        ]);

        $summary = $this->build();

        self::assertSame(1, $summary->activeServices);
        self::assertSame(2, $summary->totalServices);
        self::assertCount(1, $summary->topServices);
        self::assertSame(1199, $summary->monthlyByCategory[0]->singleMonthly()?->amountMinor);
    }

    public function testItCountsServicesByStatusInEnumOrder(): void
    {
        $paused = $this->service('En pausa', 500, BillingPeriod::MONTHLY);
        $paused->pause();
        $cancelled = $this->service('Cancelado', 500, BillingPeriod::MONTHLY);
        $cancelled->cancel(new DateTimeImmutable('2026-09-01'));

        $this->services->method('findForOrganization')->willReturn([
            $this->service('OVH VPS', 1199, BillingPeriod::MONTHLY),
            $paused,
            $cancelled,
        ]);

        $summary = $this->build();

        self::assertSame(
            [
                ['status' => ServiceStatus::ACTIVE, 'total' => 1],
                ['status' => ServiceStatus::PAUSED, 'total' => 1],
                ['status' => ServiceStatus::CANCELLED, 'total' => 1],
            ],
            $summary->servicesByStatus,
        );
    }

    public function testItBuildsAThirtyAndASixtyDayWindow(): void
    {
        $soon = $this->service('Cobro próximo', 1199, BillingPeriod::MONTHLY);
        $soon->setNextChargeAt(new DateTimeImmutable('2026-10-20'));

        $later = $this->service('Cobro lejano', 2000, BillingPeriod::MONTHLY);
        $later->setNextChargeAt(new DateTimeImmutable('2026-11-15'));

        $this->services->method('findForOrganization')->willReturn([$soon, $later]);

        $summary = $this->build();

        $window30 = $summary->window(30);
        $window60 = $summary->window(60);

        self::assertNotNull($window30);
        self::assertNotNull($window60);

        self::assertCount(1, $window30->charges);
        self::assertSame('Cobro próximo', $window30->charges[0]->getName());
        self::assertSame(1199, $window30->totalsByCurrency['EUR']->amountMinor);

        self::assertCount(2, $window60->charges);
        self::assertSame(3199, $window60->totalsByCurrency['EUR']->amountMinor);
    }

    public function testTheWindowTotalsAreKeptApartPerCurrency(): void
    {
        $euros = $this->service('OVH VPS', 1199, BillingPeriod::MONTHLY);
        $euros->setNextChargeAt(new DateTimeImmutable('2026-10-20'));

        $dollars = $this->service('GitHub', 400, BillingPeriod::MONTHLY, Currency::USD);
        $dollars->setNextChargeAt(new DateTimeImmutable('2026-10-21'));

        $this->services->method('findForOrganization')->willReturn([$euros, $dollars]);

        $summary = $this->build();
        $window = $summary->window(30);

        self::assertNotNull($window);
        self::assertSame(1199, $window->totalsByCurrency['EUR']->amountMinor);
        self::assertSame(400, $window->totalsByCurrency['USD']->amountMinor);
    }

    public function testItListsTheRenewalsOfTheNextThirtyDays(): void
    {
        $renewing = $this->service('Dominio .com', 1200, BillingPeriod::ANNUAL);
        $renewing->setRenewalAt(new DateTimeImmutable('2026-10-20'));

        $farAway = $this->service('Otro dominio', 1200, BillingPeriod::ANNUAL);
        $farAway->setRenewalAt(new DateTimeImmutable('2026-12-20'));

        $this->services->method('findForOrganization')->willReturn([$renewing, $farAway]);

        $summary = $this->build();

        self::assertCount(1, $summary->upcomingRenewals);
        self::assertSame('Dominio .com', $summary->upcomingRenewals[0]->getName());
    }

    public function testItReportsWhatHasChangedInTheLastNinetyDays(): void
    {
        $changed = $this->service('OVH VPS', 1199, BillingPeriod::MONTHLY);
        $changed->changePrice(Money::of(1499, Currency::EUR), new DateTimeImmutable('2026-10-01'));

        $old = $this->service('GitHub', 400, BillingPeriod::MONTHLY);
        $old->changePrice(Money::of(500, Currency::EUR), new DateTimeImmutable('2026-03-01'));

        $this->services->method('findForOrganization')->willReturn([$changed, $old]);

        $summary = $this->build();

        self::assertCount(1, $summary->recentPriceChanges);
        self::assertSame('OVH VPS', $summary->recentPriceChanges[0]->getName());
    }

    public function testAnEmptyOrganizationHasNothingToShow(): void
    {
        $this->services->method('findForOrganization')->willReturn([]);

        $summary = $this->build();

        self::assertFalse($summary->hasAnyData());
        self::assertFalse($summary->hasCosts());
        self::assertNull($summary->singleMonthlyTotal());
        self::assertSame([], $summary->monthlyByCategory);
        self::assertSame([], $summary->topServices);
        self::assertSame([], $summary->servicesByStatus);
    }

    public function testItCountsTheOpenAlerts(): void
    {
        $this->services->method('findForOrganization')->willReturn([]);
        $this->alerts->method('countOpen')->willReturn(3);

        self::assertSame(3, $this->build()->openAlerts);
    }

    private function build(): DashboardSummary
    {
        $builder = new BuildDashboardSummary(
            services: $this->services,
            discoveries: $this->discoveries,
            accounts: $this->accounts,
            messages: $this->messages,
            categories: $this->categories,
            alerts: $this->alerts,
            costs: new ServiceCostCalculator(),
            evolution: new SpendEvolution(),
            tenantContext: $this->tenant,
            clock: new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );

        return $builder();
    }

    private function service(
        string $name,
        int $amountMinor,
        BillingPeriod $period,
        Currency $currency = Currency::EUR,
        ?Uuid $category = null,
    ): Service {
        $service = new Service($this->organizationId, $name, $currency, $period);
        $service->changePrice(Money::of($amountMinor, $currency), new DateTimeImmutable('2026-01-15'));
        $service->setCategoryId($category);

        return $service;
    }
}
