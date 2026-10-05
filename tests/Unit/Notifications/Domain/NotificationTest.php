<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Domain;

use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Enum\NotificationChannel;
use App\Notifications\Domain\Enum\NotificationStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * El registro de entrega existe para que un correo que no salió sea visible.
 * Si el estado no reflejase la realidad, el usuario creería que nunca hubo aviso.
 */
final class NotificationTest extends TestCase
{
    private Uuid $organizationId;
    private Uuid $alertId;
    private Uuid $userId;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->alertId = Uuid::v7();
        $this->userId = Uuid::v7();
        $this->now = new DateTimeImmutable('2026-10-05 10:00:00');
    }

    public function testItStartsPending(): void
    {
        $notification = $this->notification();

        self::assertSame(NotificationStatus::PENDING, $notification->getStatus());
        self::assertNull($notification->getSentAt());
        self::assertNull($notification->getError());
    }

    public function testMarkSentClearsAnyPreviousError(): void
    {
        $notification = $this->notification();

        $retryAt = $this->now->modify('+1 hour');

        $notification->markFailed($this->now, 'El servidor de correo no responde.');
        $notification->markSent($retryAt);

        self::assertSame(NotificationStatus::SENT, $notification->getStatus());
        self::assertNull($notification->getError());
        self::assertSame($retryAt, $notification->getSentAt());
    }

    public function testMarkFailedKeepsTheReason(): void
    {
        $notification = $this->notification();

        $notification->markFailed($this->now, 'El servidor de correo no responde.');

        self::assertSame(NotificationStatus::FAILED, $notification->getStatus());
        self::assertSame('El servidor de correo no responde.', $notification->getError());
    }

    public function testMarkFailedTruncatesAnOverlongReason(): void
    {
        $notification = $this->notification();

        $notification->markFailed($this->now, str_repeat('x', 900));

        self::assertSame(500, mb_strlen((string) $notification->getError()));
    }

    public function testMarkSkippedRecordsTheReason(): void
    {
        $notification = $this->notification();

        $notification->markSkipped($this->now, 'El usuario tiene el correo desactivado.');

        self::assertSame(NotificationStatus::SKIPPED, $notification->getStatus());
        self::assertSame('El usuario tiene el correo desactivado.', $notification->getError());
    }

    public function testDedupKeyIsStableAndChannelSpecific(): void
    {
        $email = Notification::buildDedupKey($this->alertId, $this->userId, NotificationChannel::EMAIL);
        $inApp = Notification::buildDedupKey($this->alertId, $this->userId, NotificationChannel::IN_APP);

        self::assertSame($email, Notification::buildDedupKey($this->alertId, $this->userId, NotificationChannel::EMAIL));
        self::assertNotSame($email, $inApp);
    }

    public function testDedupKeyIsUserSpecific(): void
    {
        $other = Uuid::v7();

        self::assertNotSame(
            Notification::buildDedupKey($this->alertId, $this->userId, NotificationChannel::EMAIL),
            Notification::buildDedupKey($this->alertId, $other, NotificationChannel::EMAIL),
        );
    }

    private function notification(): Notification
    {
        return new Notification(
            organizationId: $this->organizationId,
            alertId: $this->alertId,
            userId: $this->userId,
            channel: NotificationChannel::EMAIL,
            dedupKey: Notification::buildDedupKey($this->alertId, $this->userId, NotificationChannel::EMAIL),
            createdAt: $this->now,
        );
    }
}
