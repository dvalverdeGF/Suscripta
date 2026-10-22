<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Application\Change;

use App\Discovery\Application\Change\ChangeSettings;
use App\Discovery\Application\Change\DetectMissingCharges;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Documents\Domain\Repository\InvoiceRepositoryInterface;
use App\Services\Domain\Dto\ServiceView;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Services\ServiceViewBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * El detector de cobros que no han llegado (Fase 15).
 *
 * Lo que hay que garantizar es que **no avisa de un problema que no existe**:
 * el margen de gracia tiene que absorber tanto la facturación anticipada como
 * el retraso del proveedor, y una factura que acaba de llegar tiene que
 * apagar la señal.
 */
final class DetectMissingChargesTest extends TestCase
{
    private const NOW = '2026-10-05 09:00:00';

    private Uuid $organizationId;
    private ServiceViewRepositoryInterface&MockObject $services;
    private InvoiceRepositoryInterface&MockObject $invoices;
    private DiscoveryRepositoryInterface&MockObject $discoveries;

    /** @var list<ServiceView> */
    private array $views = [];

    /** @var array<string, DateTimeImmutable> */
    private array $lastCharges = [];

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->services = $this->createMock(ServiceViewRepositoryInterface::class);
        $this->invoices = $this->createMock(InvoiceRepositoryInterface::class);
        $this->discoveries = $this->createMock(DiscoveryRepositoryInterface::class);
        $this->views = [];
        $this->lastCharges = [];

        $this->services->method('findAll')->willReturnCallback(fn (): array => $this->views);
        $this->invoices->method('findLastIssuedAtByService')->willReturnCallback(fn (): array => $this->lastCharges);
        $this->discoveries->method('findByDedupKey')->willReturn(null);
        $this->discoveries->method('save')->willReturnCallback(static function (): void {
        });
    }

    public function testItProposesNothingWhenThereAreNoServices(): void
    {
        self::assertSame([], $this->detect());
    }

    public function testItProposesASuspicionWhenTheChargeIsLongOverdue(): void
    {
        $this->views = [$this->service(nextChargeAt: '2026-09-20 00:00:00')];

        $proposals = $this->detect();

        self::assertCount(1, $proposals);
        self::assertSame(DiscoveryType::MISSING_CHARGE, $proposals[0]->getType());
        self::assertTrue($proposals[0]->isInference());
    }

    public function testItWaitsForTheGracePeriodToPass(): void
    {
        // Tres días de retraso: dentro del margen de gracia de siete.
        $this->views = [$this->service(nextChargeAt: '2026-10-02 00:00:00')];

        self::assertSame([], $this->detect());
    }

    public function testItIgnoresOneTimeServices(): void
    {
        $this->views = [
            ServiceViewBuilder::create('Curso de programación')
                ->withPeriod(BillingPeriod::ONE_TIME)
                ->withNextChargeAt(new DateTimeImmutable('2026-09-20 00:00:00'))
                ->build(),
        ];

        self::assertSame([], $this->detect());
    }

    public function testItIgnoresServicesThatAreNotActive(): void
    {
        $this->views = [
            ServiceViewBuilder::create('Netflix')
                ->withStatus(ServiceStatus::CANCELLED)
                ->withNextChargeAt(new DateTimeImmutable('2026-09-20 00:00:00'))
                ->build(),
        ];

        self::assertSame([], $this->detect());
    }

    public function testItIgnoresServicesWithoutANextCharge(): void
    {
        $this->views = [ServiceViewBuilder::create('Netflix')->build()];

        self::assertSame([], $this->detect());
    }

    public function testTheSignalGoesOffWhenTheInvoiceArrives(): void
    {
        $service = $this->service(nextChargeAt: '2026-09-20 00:00:00');
        $this->views = [$service];

        // La factura llegó dentro de la ventana: no hay nada que sospechar.
        $this->lastCharges = [$service->id->toRfc4122() => new DateTimeImmutable('2026-09-21 00:00:00')];

        self::assertSame([], $this->detect());
    }

    public function testItToleratesAnInvoiceIssuedBeforeTheExpectedDate(): void
    {
        $service = $this->service(nextChargeAt: '2026-09-20 00:00:00');
        $this->views = [$service];

        // Facturación anticipada: el proveedor cobra dos días antes.
        $this->lastCharges = [$service->id->toRfc4122() => new DateTimeImmutable('2026-09-18 00:00:00')];

        self::assertSame([], $this->detect());
    }

    public function testAnOldInvoiceDoesNotSilenceTheSignal(): void
    {
        $service = $this->service(nextChargeAt: '2026-09-20 00:00:00');
        $this->views = [$service];

        // La última factura es de hace meses: el cobro de septiembre no llegó.
        $this->lastCharges = [$service->id->toRfc4122() => new DateTimeImmutable('2026-03-01 00:00:00')];

        self::assertCount(1, $this->detect());
    }

    public function testTheProposalCarriesTheExpectedDate(): void
    {
        $this->views = [$this->service(nextChargeAt: '2026-09-20 00:00:00')];

        $proposals = $this->detect();
        $proposed = $proposals[0]->getProposedData();

        self::assertSame('2026-09-20', $proposed['expectedAt']);
        self::assertSame('Netflix', $proposed['serviceName']);
        self::assertSame(15, $proposed['overdueDays']);
    }

    public function testTheSignalGrowsWithTheDelay(): void
    {
        $this->views = [$this->service(nextChargeAt: '2026-09-20 00:00:00')];
        $shortDelay = $this->detect()[0]->getMatchScore();

        $this->views = [$this->service(nextChargeAt: '2026-08-20 00:00:00')];
        $longDelay = $this->detect()[0]->getMatchScore();

        // La fuerza de la señal (la puntuación de emparejamiento) crece con
        // el retraso; la confianza, en cambio, siempre está recortada a la
        // banda baja porque esto es una inferencia.
        self::assertGreaterThan($shortDelay, $longDelay);
    }

    public function testTheConfidenceNeverExceedsTheLowBand(): void
    {
        $this->views = [$this->service(nextChargeAt: '2020-01-01 00:00:00')];

        $proposals = $this->detect();

        self::assertLessThanOrEqual(54, $proposals[0]->getConfidenceScore());
    }

    public function testItDoesNotProposeTheSameChargeTwice(): void
    {
        $this->views = [$this->service(nextChargeAt: '2026-09-20 00:00:00')];

        $first = $this->detect();

        self::assertCount(1, $first);

        $this->discoveries = $this->createMock(DiscoveryRepositoryInterface::class);
        $this->discoveries->method('findByDedupKey')->willReturn($first[0]);
        $this->discoveries->method('save')->willReturnCallback(static function (): void {
        });

        self::assertSame([], $this->detect());
    }

    /**
     * @return list<Discovery>
     */
    private function detect(): array
    {
        $detector = new DetectMissingCharges(
            services: $this->services,
            invoices: $this->invoices,
            discoveries: $this->discoveries,
            settings: new ChangeSettings(),
            tenantContext: $this->tenantContext(),
            clock: new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );

        return $detector->detect();
    }

    private function service(string $nextChargeAt): ServiceView
    {
        return ServiceViewBuilder::create('Netflix')
            ->withAmount(Money::of(1299, Currency::EUR))
            ->withNextChargeAt(new DateTimeImmutable($nextChargeAt))
            ->build();
    }

    private function tenantContext(): TenantContext
    {
        $context = new TenantContext();
        $context->setOrganizationId($this->organizationId);

        return $context;
    }
}
