<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Domain;

use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertStatus;
use App\Notifications\Domain\Enum\AlertType;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * El aviso es la unidad de valor del producto: si su ciclo de vida no es
 * coherente, la bandeja deja de ser fiable y el usuario deja de mirarla.
 */
final class AlertTest extends TestCase
{
    private Uuid $organizationId;
    private Uuid $userId;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->userId = Uuid::v7();
        $this->now = new DateTimeImmutable('2026-10-05 10:00:00');
    }

    public function testItStartsOpen(): void
    {
        $alert = $this->alert();

        self::assertTrue($alert->isOpen());
        self::assertSame(AlertStatus::OPEN, $alert->getStatus());
        self::assertNull($alert->getResolvedAt());
        self::assertNull($alert->getResolvedByUserId());
    }

    public function testItRejectsAnEmptyTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->alert(title: '   ');
    }

    public function testItRejectsAnEmptyDedupKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->alert(dedupKey: '  ');
    }

    public function testItTruncatesAnOverlongTitle(): void
    {
        $alert = $this->alert(title: str_repeat('a', 300));

        self::assertSame(160, mb_strlen($alert->getTitle()));
    }

    public function testAcknowledgeClosesTheAlert(): void
    {
        $alert = $this->alert();

        $alert->acknowledge($this->userId, $this->now);

        self::assertFalse($alert->isOpen());
        self::assertSame(AlertStatus::ACKNOWLEDGED, $alert->getStatus());
        self::assertSame($this->now, $alert->getResolvedAt());
        self::assertSame($this->userId->toRfc4122(), $alert->getResolvedByUserId()?->toRfc4122());
    }

    public function testDismissClosesTheAlert(): void
    {
        $alert = $this->alert();

        $alert->dismiss($this->userId, $this->now);

        self::assertSame(AlertStatus::DISMISSED, $alert->getStatus());
        self::assertSame($this->userId->toRfc4122(), $alert->getResolvedByUserId()?->toRfc4122());
    }

    public function testResolveDoesNotRecordAUser(): void
    {
        $alert = $this->alert();

        $alert->resolve($this->now);

        self::assertSame(AlertStatus::RESOLVED, $alert->getStatus());
        self::assertNull($alert->getResolvedByUserId());
    }

    public function testAcknowledgingTwiceKeepsTheFirstDecision(): void
    {
        $alert = $this->alert();
        $later = $this->now->modify('+1 day');

        $alert->acknowledge($this->userId, $this->now);
        $alert->dismiss($this->userId, $later);

        self::assertSame(AlertStatus::ACKNOWLEDGED, $alert->getStatus());
        self::assertSame($this->now, $alert->getResolvedAt());
    }

    public function testResolvingADismissedAlertDoesNotReopenIt(): void
    {
        $alert = $this->alert();

        $alert->dismiss($this->userId, $this->now);
        $alert->resolve($this->now->modify('+1 day'));

        self::assertSame(AlertStatus::DISMISSED, $alert->getStatus());
    }

    public function testDedupKeyIsStableForTheSameInputs(): void
    {
        $serviceId = Uuid::v7();
        $dueAt = new DateTimeImmutable('2026-11-01');

        $first = Alert::buildDedupKey(AlertType::UPCOMING_CHARGE, $serviceId, $dueAt);
        $second = Alert::buildDedupKey(AlertType::UPCOMING_CHARGE, $serviceId, $dueAt);

        self::assertSame($first, $second);
    }

    public function testDedupKeyChangesWhenTheDateChanges(): void
    {
        $serviceId = Uuid::v7();

        $first = Alert::buildDedupKey(AlertType::UPCOMING_CHARGE, $serviceId, new DateTimeImmutable('2026-11-01'));
        $second = Alert::buildDedupKey(AlertType::UPCOMING_CHARGE, $serviceId, new DateTimeImmutable('2026-12-01'));

        self::assertNotSame($first, $second);
    }

    public function testDedupKeyChangesWhenTheTypeChanges(): void
    {
        $serviceId = Uuid::v7();
        $dueAt = new DateTimeImmutable('2026-11-01');

        self::assertNotSame(
            Alert::buildDedupKey(AlertType::UPCOMING_CHARGE, $serviceId, $dueAt),
            Alert::buildDedupKey(AlertType::UPCOMING_RENEWAL, $serviceId, $dueAt),
        );
    }

    public function testDedupKeyWorksWithoutAServiceOrADate(): void
    {
        $key = Alert::buildDedupKey(AlertType::DISCOVERY_PENDING, null, null);

        self::assertSame('discovery_pending|-|-', $key);
    }

    public function testMetadataIsMutable(): void
    {
        $alert = $this->alert();

        self::assertSame([], $alert->getMetadata());

        $alert->setMetadata(['daysUntil' => 3]);

        self::assertSame(['daysUntil' => 3], $alert->getMetadata());
    }

    private function alert(
        string $title = 'Cobro próximo: OVH',
        string $dedupKey = 'upcoming_charge|abc|2026-11-01',
    ): Alert {
        return new Alert(
            organizationId: $this->organizationId,
            type: AlertType::UPCOMING_CHARGE,
            severity: AlertSeverity::WARNING,
            title: $title,
            message: 'OVH te cobrará 29,90 € el 01/11/2026.',
            dedupKey: $dedupKey,
            dueAt: new DateTimeImmutable('2026-11-01'),
            createdAt: $this->now,
        );
    }
}
