<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Application;

use App\Discovery\Application\DiscoverySettings;
use App\Discovery\Application\ExpireStaleDiscoveries;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Domain\Enum\AuditAction;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Caducar lo que el usuario ha dejado sin decidir.
 *
 * Caducar **no** es borrar: la propuesta pasa a `expired` y sale de la bandeja,
 * pero se conserva con su evidencia. Es lo que permite aprender de lo que el
 * usuario ignoró sin convertir la bandeja en un vertedero.
 */
#[CoversClass(ExpireStaleDiscoveries::class)]
final class ExpireStaleDiscoveriesTest extends TestCase
{
    private const NOW = '2026-04-02 10:00:00';

    private DiscoveryRepositoryInterface&MockObject $discoveries;
    private AuditLoggerInterface&MockObject $auditLogger;

    protected function setUp(): void
    {
        $this->discoveries = $this->createMock(DiscoveryRepositoryInterface::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
    }

    public function testItExpiresTheStaleProposals(): void
    {
        $stale = $this->proposal('2025-01-10');
        $this->discoveries->method('findExpired')->willReturn([$stale]);

        $expired = ($this->expireStaleDiscoveries())();

        self::assertSame(1, $expired);
        self::assertSame(DiscoveryStatus::EXPIRED, $stale->getStatus());
    }

    public function testItDoesNothingWhenThereIsNothingStale(): void
    {
        $this->discoveries->method('findExpired')->willReturn([]);

        $expired = ($this->expireStaleDiscoveries())();

        self::assertSame(0, $expired);
        $this->discoveries->expects(self::never())->method('flush');
        $this->auditLogger->expects(self::never())->method('log');
    }

    public function testItIsIdempotent(): void
    {
        $proposal = $this->proposal('2025-01-10');
        $this->discoveries->method('findExpired')->willReturn([$proposal]);

        $expire = $this->expireStaleDiscoveries();
        self::assertSame(1, $expire());

        // El repositorio ya no devuelve lo caducado: la segunda pasada no hace nada.
        $this->discoveries = $this->createMock(DiscoveryRepositoryInterface::class);
        $this->discoveries->method('findExpired')->willReturn([]);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);

        $second = new ExpireStaleDiscoveries(
            $this->discoveries,
            new DiscoverySettings(),
            $this->auditLogger,
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );

        self::assertSame(0, $second());
        self::assertSame(DiscoveryStatus::EXPIRED, $proposal->getStatus());
    }

    public function testItAuditsTheExpiry(): void
    {
        $proposal = $this->proposal('2025-01-10');
        $this->discoveries->method('findExpired')->willReturn([$proposal]);

        $this->auditLogger
            ->expects(self::once())
            ->method('log')
            ->with(
                AuditAction::DISCOVERY_EXPIRED,
                'discovery',
                $proposal->getId()->toRfc4122(),
                self::anything(),
            );

        ($this->expireStaleDiscoveries())();
    }

    public function testTheThresholdFollowsTheConfiguredExpiryDays(): void
    {
        $settings = new DiscoverySettings(expiryDays: 30);
        $threshold = $settings->expiryThreshold(new DateTimeImmutable(self::NOW));

        self::assertSame('2026-03-03', $threshold->format('Y-m-d'));
    }

    public function testTheIgnoredSuppressionWindowFollowsItsOwnSetting(): void
    {
        $settings = new DiscoverySettings(expiryDays: 30, ignoreSuppressionDays: 7);
        $since = $settings->ignoredSince(new DateTimeImmutable(self::NOW));

        self::assertSame('2026-03-26', $since->format('Y-m-d'));
    }

    private function expireStaleDiscoveries(): ExpireStaleDiscoveries
    {
        return new ExpireStaleDiscoveries(
            $this->discoveries,
            new DiscoverySettings(),
            $this->auditLogger,
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );
    }

    private function proposal(string $detectedAt): Discovery
    {
        return new Discovery(
            Uuid::v7(),
            DiscoveryType::NEW_SERVICE,
            new DateTimeImmutable($detectedAt),
            ['providerName' => 'Netflix', 'amountMinor' => 1290],
        );
    }
}
