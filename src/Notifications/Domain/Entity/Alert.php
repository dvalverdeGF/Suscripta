<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Entity;

use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertStatus;
use App\Notifications\Domain\Enum\AlertType;
use App\Shared\Domain\Contract\TenantAwareInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function mb_substr;
use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * Un aviso que el sistema ha generado para el usuario (ARCHITECTURE.md §4.7).
 *
 * `dedupKey` es lo que hace que la generación sea **idempotente**: el comando
 * de alertas se puede ejecutar cada hora sin que el usuario reciba el mismo
 * aviso dos veces. La clave incluye el servicio y la fecha objetivo, así que un
 * cobro que se retrasa una semana genera un aviso nuevo y el anterior se
 * resuelve solo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'alert')]
#[ORM\Index(name: 'idx_alert_organization_status', columns: ['organization_id', 'status'])]
#[ORM\UniqueConstraint(name: 'uniq_alert_dedup_key', columns: ['organization_id', 'dedup_key'])]
#[ORM\Index(name: 'idx_alert_service', columns: ['service_id'])]
class Alert implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    #[ORM\Column(name: 'service_id', type: 'uuid', nullable: true)]
    private ?Uuid $serviceId;

    #[ORM\Column(name: 'discovery_id', type: 'uuid', nullable: true)]
    private ?Uuid $discoveryId;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: AlertType::class)]
    private AlertType $type;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: AlertSeverity::class)]
    private AlertSeverity $severity;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: AlertStatus::class)]
    private AlertStatus $status = AlertStatus::OPEN;

    #[ORM\Column(type: Types::STRING, length: 160)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    /** Fecha a la que se refiere el aviso: el cobro, la renovación, el plazo. */
    #[ORM\Column(name: 'due_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $dueAt;

    #[ORM\Column(name: 'dedup_key', type: Types::STRING, length: 255)]
    private string $dedupKey;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'resolved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(name: 'resolved_by_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $resolvedByUserId = null;

    public function __construct(
        Uuid $organizationId,
        AlertType $type,
        AlertSeverity $severity,
        string $title,
        string $message,
        string $dedupKey,
        ?DateTimeImmutable $dueAt = null,
        ?Uuid $serviceId = null,
        ?Uuid $discoveryId = null,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $title = trim($title);

        if ('' === $title) {
            throw new InvalidArgumentException('El título del aviso no puede estar vacío.');
        }

        if ('' === trim($dedupKey)) {
            throw new InvalidArgumentException('Un aviso necesita una clave de deduplicación.');
        }

        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->type = $type;
        $this->severity = $severity;
        $this->title = mb_substr($title, 0, 160);
        $this->message = $message;
        $this->dedupKey = mb_substr($dedupKey, 0, 255);
        $this->dueAt = $dueAt;
        $this->serviceId = $serviceId;
        $this->discoveryId = $discoveryId;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    /**
     * Clave estable para no repetir un aviso.
     *
     * Incluye la fecha objetivo porque un cobro que se retrasa es, a efectos
     * prácticos, un aviso nuevo: el anterior ya no describe la realidad.
     */
    public static function buildDedupKey(AlertType $type, ?Uuid $serviceId, ?DateTimeImmutable $dueAt): string
    {
        return mb_substr(sprintf(
            '%s|%s|%s',
            $type->value,
            $serviceId?->toRfc4122() ?? '-',
            $dueAt?->format('Y-m-d') ?? '-',
        ), 0, 255);
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

    public function getServiceId(): ?Uuid
    {
        return $this->serviceId;
    }

    public function getDiscoveryId(): ?Uuid
    {
        return $this->discoveryId;
    }

    public function getType(): AlertType
    {
        return $this->type;
    }

    public function getSeverity(): AlertSeverity
    {
        return $this->severity;
    }

    public function getStatus(): AlertStatus
    {
        return $this->status;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getDueAt(): ?DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function getDedupKey(): string
    {
        return $this->dedupKey;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /** @param array<string, mixed> $metadata */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getResolvedByUserId(): ?Uuid
    {
        return $this->resolvedByUserId;
    }

    public function acknowledge(Uuid $userId, DateTimeImmutable $at): void
    {
        if (!$this->status->isOpen()) {
            return;
        }

        $this->status = AlertStatus::ACKNOWLEDGED;
        $this->resolvedAt = $at;
        $this->resolvedByUserId = $userId;
    }

    public function dismiss(Uuid $userId, DateTimeImmutable $at): void
    {
        if (!$this->status->isOpen()) {
            return;
        }

        $this->status = AlertStatus::DISMISSED;
        $this->resolvedAt = $at;
        $this->resolvedByUserId = $userId;
    }

    /**
     * Cierra el aviso porque la situación que lo motivó ya no se da.
     *
     * Lo llama el generador, no el usuario: si el cobro ya ha pasado o el
     * servicio se ha cancelado, el aviso deja de tener sentido y no debe
     * quedarse en la bandeja para siempre.
     */
    public function resolve(DateTimeImmutable $at): void
    {
        if (!$this->status->isOpen()) {
            return;
        }

        $this->status = AlertStatus::RESOLVED;
        $this->resolvedAt = $at;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
