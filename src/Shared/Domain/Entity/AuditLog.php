<?php

declare(strict_types=1);

namespace App\Shared\Domain\Entity;

use App\Shared\Domain\Enum\AuditAction;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Registro de auditoría, append-only (SECURITY.md §7).
 *
 * No implementa TenantAwareInterface a propósito: `organizationId` es nullable
 * porque hay acciones de sistema (altas, intentos de acceso fallidos) que
 * ocurren antes de que exista una organización activa. El repositorio filtra
 * siempre por organización de forma explícita.
 *
 * Nunca se guarda aquí el contenido de un correo ni datos personales más allá
 * del identificador del actor.
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_log_organization_created', columns: ['organization_id', 'created_at'])]
#[ORM\Index(name: 'idx_audit_log_action_created', columns: ['action', 'created_at'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid', nullable: true)]
    private ?Uuid $organizationId;

    #[ORM\Column(name: 'actor_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $actorUserId;

    #[ORM\Column(name: 'actor_type', type: Types::STRING, length: 20)]
    private string $actorType;

    #[ORM\Column(type: Types::STRING, length: 60, enumType: AuditAction::class)]
    private AuditAction $action;

    #[ORM\Column(name: 'target_type', type: Types::STRING, length: 60, nullable: true)]
    private ?string $targetType;

    #[ORM\Column(name: 'target_id', type: Types::STRING, length: 64, nullable: true)]
    private ?string $targetId;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata;

    #[ORM\Column(name: 'ip_address', type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipAddress;

    #[ORM\Column(name: 'user_agent', type: Types::STRING, length: 255, nullable: true)]
    private ?string $userAgent;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        AuditAction $action,
        ?Uuid $organizationId = null,
        ?Uuid $actorUserId = null,
        string $actorType = 'user',
        ?string $targetType = null,
        ?string $targetId = null,
        array $metadata = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->id = Uuid::v7();
        $this->action = $action;
        $this->organizationId = $organizationId;
        $this->actorUserId = $actorUserId;
        $this->actorType = $actorType;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->metadata = $metadata;
        $this->ipAddress = $ipAddress;
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrganizationId(): ?Uuid
    {
        return $this->organizationId;
    }

    public function getActorUserId(): ?Uuid
    {
        return $this->actorUserId;
    }

    public function getActorType(): string
    {
        return $this->actorType;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getTargetType(): ?string
    {
        return $this->targetType;
    }

    public function getTargetId(): ?string
    {
        return $this->targetId;
    }

    /** @return array<string, scalar|null> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
