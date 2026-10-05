<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Entity;

use App\Notifications\Domain\Enum\NotificationChannel;
use App\Notifications\Domain\Enum\NotificationStatus;
use App\Shared\Domain\Contract\TenantAwareInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function mb_substr;
use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * El intento de entregar un aviso por un canal concreto (ARCHITECTURE.md §4.7).
 *
 * Se separa de `Alert` porque un mismo aviso puede entregarse por dos canales y
 * porque el resultado de cada intento hay que poder auditar: si un correo no
 * salió, el usuario tiene que poder verlo en lugar de creer que nunca hubo
 * aviso.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification')]
#[ORM\Index(name: 'idx_notification_organization', columns: ['organization_id', 'created_at'])]
#[ORM\Index(name: 'idx_notification_alert', columns: ['alert_id'])]
#[ORM\UniqueConstraint(name: 'uniq_notification_dedup_key', columns: ['organization_id', 'dedup_key'])]
class Notification implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    #[ORM\Column(name: 'alert_id', type: 'uuid')]
    private Uuid $alertId;

    #[ORM\Column(name: 'user_id', type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: NotificationChannel::class)]
    private NotificationChannel $channel;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: NotificationStatus::class)]
    private NotificationStatus $status = NotificationStatus::PENDING;

    #[ORM\Column(name: 'dedup_key', type: Types::STRING, length: 255)]
    private string $dedupKey;

    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $organizationId,
        Uuid $alertId,
        Uuid $userId,
        NotificationChannel $channel,
        string $dedupKey,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->alertId = $alertId;
        $this->userId = $userId;
        $this->channel = $channel;
        $this->dedupKey = mb_substr($dedupKey, 0, 255);
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    public static function buildDedupKey(Uuid $alertId, Uuid $userId, NotificationChannel $channel): string
    {
        return mb_substr(sprintf('%s|%s|%s', $alertId->toRfc4122(), $userId->toRfc4122(), $channel->value), 0, 255);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrganizationId(): Uuid
    {
        return $this->organizationId;
    }

    public function setOrganizationId(Uuid $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    public function getAlertId(): Uuid
    {
        return $this->alertId;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function getStatus(): NotificationStatus
    {
        return $this->status;
    }

    public function getDedupKey(): string
    {
        return $this->dedupKey;
    }

    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function markSent(DateTimeImmutable $at): void
    {
        $this->status = NotificationStatus::SENT;
        $this->sentAt = $at;
        $this->error = null;
    }

    public function markFailed(DateTimeImmutable $at, string $error): void
    {
        $this->status = NotificationStatus::FAILED;
        $this->sentAt = $at;
        $this->error = mb_substr($error, 0, 500);
    }

    /**
     * El canal no aplicaba: el usuario lo tiene desactivado o el aviso no
     * justifica interrumpir. No es un fallo y no debe reintentarse.
     */
    public function markSkipped(DateTimeImmutable $at, string $reason): void
    {
        $this->status = NotificationStatus::SKIPPED;
        $this->sentAt = $at;
        $this->error = mb_substr($reason, 0, 500);
    }
}
