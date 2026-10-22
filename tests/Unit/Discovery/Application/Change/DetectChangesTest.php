<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Application\Change;

use App\Discovery\Application\Change\ChangeDetectionResult;
use App\Discovery\Application\Change\ChangeSettings;
use App\Discovery\Application\Change\DetectChanges;
use App\Discovery\Application\Change\DetectDormantServices;
use App\Discovery\Application\Change\DetectDuplicates;
use App\Discovery\Application\Change\DetectMissingCharges;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Documents\Domain\Repository\InvoiceRepositoryInterface;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
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
 * El barrido agregado (Fase 15).
 *
 * Comprueba que los tres detectores trabajan juntos y que el recuento por
 * tipo refleja lo que se ha propuesto de verdad.
 */
final class DetectChangesTest extends TestCase
{
    private const NOW = '2026-10-05 09:00:00';

    private Uuid $organizationId;
    private ServiceViewRepositoryInterface&MockObject $services;
    private InvoiceRepositoryInterface&MockObject $invoices;
    private DiscoveryRepositoryInterface&MockObject $discoveries;
    private AuditLoggerInterface&MockObject $auditLogger;

    /** @var list<\App\Services\Domain\Dto\ServiceView> */
    private array $views = [];

    /** @var array<string, DateTimeImmutable> */
    private array $lastCharges = [];

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->services = $this->createMock(ServiceViewRepositoryInterface::class);
        $this->invoices = $this->createMock(InvoiceRepositoryInterface::class);
        $this->discoveries = $this->createMock(DiscoveryRepositoryInterface::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->views = [];
        $this->lastCharges = [];

        $this->services->method('findAll')->willReturnCallback(fn (): array => $this->views);
        $this->invoices->method('findLastIssuedAtByService')->willReturnCallback(fn (): array => $this->lastCharges);
        $this->discoveries->method('findByDedupKey')->willReturn(null);
        $this->discoveries->method('save')->willReturnCallback(static function (): void {
        });
    }

    public function testItReportsNothingOnAnEmptyInventory(): void
    {
        $result = $this->sweep();

        self::assertInstanceOf(ChangeDetectionResult::class, $result);
        self::assertSame(0, $result->total());
        self::assertSame(0, $result->servicesInspected);
        self::assertSame([], $result->countsByType);
    }

    public function testItAggregatesTheThreeKindsOfSuspicion(): void
    {
        // Un par de duplicados.
        $provider = Uuid::v7();
        $this->views = [
            ServiceViewBuilder::create('Netflix')
                ->withProvider($provider)
                ->withAmount(Money::of(1299, Currency::EUR))
                ->withStartedAt(new DateTimeImmutable('2024-01-01 00:00:00'))
                ->withCreatedAt(new DateTimeImmutable('2024-01-01 00:00:00'))
                ->withEvent(ServiceEventType::RENEWED, new DateTimeImmutable('2026-09-20 00:00:00'))
                ->build(),
            ServiceViewBuilder::create('Netflix Premium')
                ->withProvider($provider)
                ->withAmount(Money::of(1299, Currency::EUR))
                ->withStartedAt(new DateTimeImmutable('2025-06-01 00:00:00'))
                ->withCreatedAt(new DateTimeImmutable('2025-06-01 00:00:00'))
                ->withEvent(ServiceEventType::RENEWED, new DateTimeImmutable('2026-09-20 00:00:00'))
                ->build(),
            // Un cobro que no ha llegado y, además, meses sin actividad.
            ServiceViewBuilder::create('Spotify')
                ->withAmount(Money::of(1099, Currency::EUR))
                ->withNextChargeAt(new DateTimeImmutable('2026-08-01 00:00:00'))
                ->withCreatedAt(new DateTimeImmutable('2024-01-01 00:00:00'))
                ->build(),
        ];

        $result = $this->sweep();

        self::assertSame(3, $result->total());
        self::assertSame(1, $result->countOf(DiscoveryType::DUPLICATE));
        self::assertSame(1, $result->countOf(DiscoveryType::MISSING_CHARGE));
        self::assertSame(1, $result->countOf(DiscoveryType::DORMANT));
        self::assertSame(3, $result->servicesInspected);
    }

    public function testItCountsTheInspectedServices(): void
    {
        $this->views = [
            ServiceViewBuilder::create('Netflix')->withCreatedAt(new DateTimeImmutable('2026-09-01 00:00:00'))->build(),
            ServiceViewBuilder::create('Spotify')->withCreatedAt(new DateTimeImmutable('2026-09-02 00:00:00'))->build(),
        ];

        $result = $this->sweep();

        self::assertSame(2, $result->servicesInspected);
    }

    public function testEveryProposalIsAnInference(): void
    {
        $provider = Uuid::v7();
        $this->views = [
            ServiceViewBuilder::create('Netflix')
                ->withProvider($provider)
                ->withAmount(Money::of(1299, Currency::EUR))
                ->withStartedAt(new DateTimeImmutable('2024-01-01 00:00:00'))
                ->withCreatedAt(new DateTimeImmutable('2024-01-01 00:00:00'))
                ->withEvent(ServiceEventType::RENEWED, new DateTimeImmutable('2026-09-20 00:00:00'))
                ->build(),
            ServiceViewBuilder::create('Netflix Premium')
                ->withProvider($provider)
                ->withAmount(Money::of(1299, Currency::EUR))
                ->withStartedAt(new DateTimeImmutable('2025-06-01 00:00:00'))
                ->withCreatedAt(new DateTimeImmutable('2025-06-01 00:00:00'))
                ->withEvent(ServiceEventType::RENEWED, new DateTimeImmutable('2026-09-20 00:00:00'))
                ->build(),
        ];

        foreach ($this->sweep()->proposals as $proposal) {
            self::assertTrue($proposal->isInference());
        }
    }

    private function sweep(): ChangeDetectionResult
    {
        $settings = new ChangeSettings();
        $tenantContext = new TenantContext();
        $tenantContext->setOrganizationId($this->organizationId);
        $clock = new Clock(new MockClock(new DateTimeImmutable(self::NOW)));

        $detectChanges = new DetectChanges(
            services: $this->services,
            duplicates: new DetectDuplicates(
                services: $this->services,
                discoveries: $this->discoveries,
                settings: $settings,
                tenantContext: $tenantContext,
                clock: $clock,
            ),
            missingCharges: new DetectMissingCharges(
                services: $this->services,
                invoices: $this->invoices,
                discoveries: $this->discoveries,
                settings: $settings,
                tenantContext: $tenantContext,
                clock: $clock,
            ),
            dormantServices: new DetectDormantServices(
                services: $this->services,
                invoices: $this->invoices,
                discoveries: $this->discoveries,
                settings: $settings,
                tenantContext: $tenantContext,
                clock: $clock,
            ),
            auditLogger: $this->auditLogger,
        );

        return $detectChanges();
    }
}
