<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Application;

use App\Discovery\Application\ConfirmInference;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Entity\ServiceEvent;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Qué pasa cuando el usuario acepta una inferencia (Fase 15, D-59).
 *
 * La regla que hay que proteger es una: **aceptar una sospecha nunca borra
 * historia**. El servicio sospechoso se cancela, pero su historial se
 * conserva; y el original recibe una nota, no un borrado.
 */
final class ConfirmInferenceTest extends TestCase
{
    private const NOW = '2026-10-05 09:00:00';

    private Uuid $organizationId;
    private DiscoveryRepositoryInterface&MockObject $discoveries;
    private ServiceRepositoryInterface&MockObject $services;
    private AuditLoggerInterface&MockObject $auditLogger;

    /** @var array<string, Service> */
    private array $serviceList = [];

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->discoveries = $this->createMock(DiscoveryRepositoryInterface::class);
        $this->services = $this->createMock(ServiceRepositoryInterface::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->serviceList = [];

        $this->services->method('find')->willReturnCallback(
            fn (Uuid $id): ?Service => $this->findService($id),
        );
        $this->services->method('save')->willReturnCallback(static function (): void {
        });
        $this->discoveries->method('save')->willReturnCallback(static function (): void {
        });
    }

    private function findService(Uuid $id): ?Service
    {
        return $this->serviceList[$id->toRfc4122()] ?? null;
    }

    public function testItRefusesAProposalThatIsNotAnInference(): void
    {
        $discovery = $this->discovery(DiscoveryType::NEW_SERVICE, ['providerName' => 'Netflix']);

        $this->expectException(InvalidArgumentException::class);

        $this->confirm($discovery);
    }

    public function testItRefusesAProposalThatHasAlreadyBeenResolved(): void
    {
        $discovery = $this->discovery(DiscoveryType::DORMANT, []);
        $discovery->ignore(Uuid::v7(), new DateTimeImmutable(self::NOW));

        $this->expectException(InvalidArgumentException::class);

        $this->confirm($discovery);
    }

    public function testConfirmingADuplicateCancelsTheNewestService(): void
    {
        $original = $this->service('Netflix');
        $duplicate = $this->service('Netflix Familiar');
        $this->serviceList = [
            $original->getId()->toRfc4122() => $original,
            $duplicate->getId()->toRfc4122() => $duplicate,
        ];

        $discovery = $this->discovery(DiscoveryType::DUPLICATE, [
            'serviceId' => $original->getId()->toRfc4122(),
            'relatedServiceId' => $duplicate->getId()->toRfc4122(),
            'serviceName' => 'Netflix',
            'relatedServiceName' => 'Netflix Familiar',
        ]);
        $discovery->applyMatch(90, [], $original->getId());

        $result = $this->confirm($discovery);

        self::assertNotNull($result);
        self::assertTrue($result->getId()->equals($original->getId()));
        self::assertSame(ServiceStatus::CANCELLED, $duplicate->getStatus());
        self::assertSame(ServiceStatus::ACTIVE, $original->getStatus());
    }

    public function testConfirmingADuplicateKeepsTheHistoryOfTheCancelledService(): void
    {
        $original = $this->service('Netflix');
        $duplicate = $this->service('Netflix Familiar');
        $this->serviceList = [
            $original->getId()->toRfc4122() => $original,
            $duplicate->getId()->toRfc4122() => $duplicate,
        ];

        $discovery = $this->discovery(DiscoveryType::DUPLICATE, [
            'serviceId' => $original->getId()->toRfc4122(),
            'relatedServiceId' => $duplicate->getId()->toRfc4122(),
        ]);
        $discovery->applyMatch(90, [], $original->getId());

        $this->confirm($discovery);

        // El servicio cancelado conserva su historial: se le añade la
        // cancelación y la nota que explica por qué se canceló.
        $types = array_map(
            static fn (ServiceEvent $event): ServiceEventType => $event->getType(),
            $duplicate->getEvents()->toArray(),
        );

        self::assertSame([ServiceEventType::CANCELLED, ServiceEventType::NOTE_ADDED], $types);
    }

    public function testConfirmingADuplicateLeavesATraceInTheOriginal(): void
    {
        $original = $this->service('Netflix');
        $duplicate = $this->service('Netflix Familiar');
        $this->serviceList = [
            $original->getId()->toRfc4122() => $original,
            $duplicate->getId()->toRfc4122() => $duplicate,
        ];

        $discovery = $this->discovery(DiscoveryType::DUPLICATE, [
            'serviceId' => $original->getId()->toRfc4122(),
            'relatedServiceId' => $duplicate->getId()->toRfc4122(),
        ]);
        $discovery->applyMatch(90, [], $original->getId());

        $this->confirm($discovery);

        $types = array_map(
            static fn (ServiceEvent $event): ServiceEventType => $event->getType(),
            $original->getEvents()->toArray(),
        );

        self::assertSame([ServiceEventType::NOTE_ADDED], $types);
    }

    public function testConfirmingAMissingChargeMovesTheNextChargeIntoTheFuture(): void
    {
        $service = $this->service('Netflix');
        $service->setNextChargeAt(new DateTimeImmutable('2026-08-20 00:00:00'));
        $this->serviceList = [$service->getId()->toRfc4122() => $service];

        $discovery = $this->discovery(DiscoveryType::MISSING_CHARGE, [
            'serviceId' => $service->getId()->toRfc4122(),
            'expectedAt' => '2026-08-20',
        ]);
        $discovery->applyMatch(70, [], $service->getId());

        $this->confirm($discovery);

        $nextChargeAt = $service->getNextChargeAt();

        self::assertNotNull($nextChargeAt);
        self::assertGreaterThan(new DateTimeImmutable(self::NOW), $nextChargeAt);
    }

    public function testConfirmingAMissingChargeLeavesANote(): void
    {
        $service = $this->service('Netflix');
        $service->setNextChargeAt(new DateTimeImmutable('2026-08-20 00:00:00'));
        $this->serviceList = [$service->getId()->toRfc4122() => $service];

        $discovery = $this->discovery(DiscoveryType::MISSING_CHARGE, [
            'serviceId' => $service->getId()->toRfc4122(),
            'expectedAt' => '2026-08-20',
        ]);
        $discovery->applyMatch(70, [], $service->getId());

        $this->confirm($discovery);

        $types = array_map(
            static fn (ServiceEvent $event): ServiceEventType => $event->getType(),
            $service->getEvents()->toArray(),
        );

        self::assertSame([ServiceEventType::NOTE_ADDED], $types);
    }

    public function testConfirmingADormantServiceCancelsIt(): void
    {
        $service = $this->service('Netflix');
        $this->serviceList = [$service->getId()->toRfc4122() => $service];

        $discovery = $this->discovery(DiscoveryType::DORMANT, [
            'serviceId' => $service->getId()->toRfc4122(),
        ]);
        $discovery->applyMatch(80, [], $service->getId());

        $result = $this->confirm($discovery);

        self::assertNotNull($result);
        self::assertSame(ServiceStatus::CANCELLED, $service->getStatus());
    }

    public function testTheProposalIsMarkedAsConfirmed(): void
    {
        $service = $this->service('Netflix');
        $this->serviceList = [$service->getId()->toRfc4122() => $service];

        $discovery = $this->discovery(DiscoveryType::DORMANT, [
            'serviceId' => $service->getId()->toRfc4122(),
        ]);
        $discovery->applyMatch(80, [], $service->getId());

        $this->confirm($discovery);

        self::assertSame(DiscoveryStatus::CONFIRMED, $discovery->getStatus());
        self::assertNotNull($discovery->getReviewedAt());
    }

    private function confirm(Discovery $discovery): ?Service
    {
        $confirmInference = new ConfirmInference(
            discoveries: $this->discoveries,
            services: $this->services,
            tenantContext: $this->tenantContext(),
            auditLogger: $this->auditLogger,
            clock: new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );

        return $confirmInference($discovery, Uuid::v7());
    }

    private function tenantContext(): TenantContext
    {
        $context = new TenantContext();
        $context->setOrganizationId($this->organizationId);

        return $context;
    }

    /**
     * @param array<string, mixed> $proposedData
     */
    private function discovery(DiscoveryType $type, array $proposedData): Discovery
    {
        return new Discovery(
            organizationId: $this->organizationId,
            type: $type,
            detectedAt: new DateTimeImmutable(self::NOW),
            proposedData: $proposedData,
        );
    }

    private function service(string $name): Service
    {
        return new Service(
            organizationId: $this->organizationId,
            name: $name,
            currency: Currency::EUR,
            billingPeriod: BillingPeriod::MONTHLY,
            source: ServiceSource::MANUAL,
            status: ServiceStatus::ACTIVE,
        );
    }
}
