<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Application\Change;

use App\Discovery\Application\Change\ChangeSettings;
use App\Discovery\Application\Change\DetectDormantServices;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Documents\Domain\Repository\InvoiceRepositoryInterface;
use App\Services\Domain\Dto\ServiceView;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Services\ServiceViewBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * El detector de servicios sin actividad (Fase 15).
 *
 * Lo que hay que garantizar es que **solo cuenta como actividad la actividad
 * real**: editar una nota o pausar un servicio no dice nada sobre si el
 * negocio sigue vivo, y si contara, el detector no encontraría nunca nada.
 */
final class DetectDormantServicesTest extends TestCase
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

    public function testItProposesASuspicionForAServiceWithoutSignals(): void
    {
        $this->views = [
            ServiceViewBuilder::create('Netflix')
                ->withAmount(Money::of(1299, Currency::EUR))
                ->withCreatedAt(new DateTimeImmutable('2025-01-01 00:00:00'))
                ->build(),
        ];

        $proposals = $this->detect();

        self::assertCount(1, $proposals);
        self::assertSame(DiscoveryType::DORMANT, $proposals[0]->getType());
        self::assertTrue($proposals[0]->isInference());
    }

    public function testItIgnoresAServiceThatIsStillActive(): void
    {
        $this->views = [
            ServiceViewBuilder::create('Netflix')
                ->withAmount(Money::of(1299, Currency::EUR))
                ->withCreatedAt(new DateTimeImmutable('2026-09-01 00:00:00'))
                ->build(),
        ];

        self::assertSame([], $this->detect());
    }

    public function testItIgnoresServicesThatAreNotActive(): void
    {
        $this->views = [
            ServiceViewBuilder::create('Netflix')
                ->withStatus(ServiceStatus::CANCELLED)
                ->withCreatedAt(new DateTimeImmutable('2025-01-01 00:00:00'))
                ->build(),
        ];

        self::assertSame([], $this->detect());
    }

    public function testAnInvoiceIsASignalOfLife(): void
    {
        $service = ServiceViewBuilder::create('Netflix')
            ->withAmount(Money::of(1299, Currency::EUR))
            ->withCreatedAt(new DateTimeImmutable('2025-01-01 00:00:00'))
            ->build();

        $this->views = [$service];
        $this->lastCharges = [$service->id->toRfc4122() => new DateTimeImmutable('2026-09-20 00:00:00')];

        self::assertSame([], $this->detect());
    }

    public function testARenewalIsASignalOfLife(): void
    {
        $this->views = [
            ServiceViewBuilder::create('Netflix')
                ->withAmount(Money::of(1299, Currency::EUR))
                ->withCreatedAt(new DateTimeImmutable('2025-01-01 00:00:00'))
                ->withEvent(ServiceEventType::RENEWED, new DateTimeImmutable('2026-09-20 00:00:00'))
                ->build(),
        ];

        self::assertSame([], $this->detect());
    }

    public function testEditingTheServiceIsNotASignalOfLife(): void
    {
        // Un evento de gestión (cambio de precio, nota, pausa) no dice nada
        // sobre si el negocio sigue vivo.
        $this->views = [
            ServiceViewBuilder::create('Netflix')
                ->withAmount(Money::of(1299, Currency::EUR))
                ->withCreatedAt(new DateTimeImmutable('2025-01-01 00:00:00'))
                ->withEvent(ServiceEventType::PRICE_CHANGED, new DateTimeImmutable('2026-09-20 00:00:00'))
                ->withEvent(ServiceEventType::NOTE_ADDED, new DateTimeImmutable('2026-09-25 00:00:00'))
                ->withEvent(ServiceEventType::PAUSED, new DateTimeImmutable('2026-09-26 00:00:00'))
                ->build(),
        ];

        self::assertCount(1, $this->detect());
    }

    public function testTheProposalCarriesTheInactiveDays(): void
    {
        $this->views = [
            ServiceViewBuilder::create('Netflix')
                ->withAmount(Money::of(1299, Currency::EUR))
                ->withCreatedAt(new DateTimeImmutable('2026-01-01 00:00:00'))
                ->build(),
        ];

        $proposals = $this->detect();
        $proposed = $proposals[0]->getProposedData();

        self::assertSame('Netflix', $proposed['serviceName']);
        self::assertSame('2026-01-01', $proposed['inactiveSince']);
        self::assertSame(277, $proposed['inactiveDays']);
    }

    public function testTheConfidenceNeverExceedsTheLowBand(): void
    {
        $this->views = [
            ServiceViewBuilder::create('Netflix')
                ->withAmount(Money::of(1299, Currency::EUR))
                ->withCreatedAt(new DateTimeImmutable('2019-01-01 00:00:00'))
                ->build(),
        ];

        $proposals = $this->detect();

        self::assertLessThanOrEqual(54, $proposals[0]->getConfidenceScore());
    }

    public function testItDoesNotProposeTheSameEpisodeTwice(): void
    {
        $this->views = [
            ServiceViewBuilder::create('Netflix')
                ->withAmount(Money::of(1299, Currency::EUR))
                ->withCreatedAt(new DateTimeImmutable('2026-01-01 00:00:00'))
                ->build(),
        ];

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
        $detector = new DetectDormantServices(
            services: $this->services,
            invoices: $this->invoices,
            discoveries: $this->discoveries,
            settings: new ChangeSettings(),
            tenantContext: $this->tenantContext(),
            clock: new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );

        return $detector->detect();
    }

    private function tenantContext(): TenantContext
    {
        $context = new TenantContext();
        $context->setOrganizationId($this->organizationId);

        return $context;
    }
}
