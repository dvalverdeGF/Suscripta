<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Entity;

use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Enum\NotificationChannel;
use App\Shared\Domain\Contract\TenantAwareInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Qué avisos quiere recibir un usuario y por dónde (ARCHITECTURE.md §4.7).
 *
 * Solo se guardan las **desviaciones** del comportamiento por defecto. Si no
 * hay fila, se aplica el valor por defecto del tipo de aviso. Así, añadir un
 * tipo de aviso nuevo no obliga a migrar las preferencias de nadie.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_preference')]
#[ORM\UniqueConstraint(name: 'uniq_notification_preference', columns: ['user_id', 'alert_type', 'channel'])]
class NotificationPreference implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    #[ORM\Column(name: 'user_id', type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(name: 'alert_type', type: Types::STRING, length: 30, enumType: AlertType::class)]
    private AlertType $alertType;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: NotificationChannel::class)]
    private NotificationChannel $channel;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $organizationId,
        Uuid $userId,
        AlertType $alertType,
        NotificationChannel $channel,
        bool $enabled,
        ?DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->userId = $userId;
        $this->alertType = $alertType;
        $this->channel = $channel;
        $this->enabled = $enabled;
        $this->updatedAt = $updatedAt ?? new DateTimeImmutable();
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

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getAlertType(): AlertType
    {
        return $this->alertType;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled, DateTimeImmutable $at): void
    {
        $this->enabled = $enabled;
        $this->updatedAt = $at;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
