<?php

declare(strict_types=1);

namespace App\Ai\Domain\Entity;

use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Shared\Domain\Contract\TenantAwareInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Una llamada a un proveedor de IA, con lo que costó.
 *
 * Es la fuente de verdad del gasto en IA (D-36). Sin ella no se puede
 * responder a la pregunta más importante del producto: «¿cuánto me cuesta
 * esto?». Se registra **siempre**, incluso cuando la llamada falla, porque un
 * fallo también consume tokens.
 *
 * Guarda la organización porque el gasto es por cliente: el coste se reparte
 * entre las organizaciones que lo han generado.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_usage')]
#[ORM\Index(name: 'idx_ai_usage_organization_created', columns: ['organization_id', 'created_at'])]
class AiUsage implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    #[ORM\Column(name: 'provider_key', type: Types::STRING, length: 60)]
    private string $providerKey;

    #[ORM\Column(name: 'purpose', type: Types::STRING, length: 40, enumType: AiPurpose::class)]
    private AiPurpose $purpose;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: AiTier::class)]
    private AiTier $tier;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $model;

    #[ORM\Column(name: 'prompt_tokens', type: Types::INTEGER)]
    private int $promptTokens = 0;

    #[ORM\Column(name: 'completion_tokens', type: Types::INTEGER)]
    private int $completionTokens = 0;

    #[ORM\Column(name: 'cost_micro', type: Types::BIGINT)]
    private int $costMicro = 0;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $successful = true;

    #[ORM\Column(name: 'duration_ms', type: Types::INTEGER)]
    private int $durationMs = 0;

    #[ORM\Column(name: 'entity_type', type: Types::STRING, length: 60, nullable: true)]
    private ?string $entityType = null;

    #[ORM\Column(name: 'entity_id', type: 'uuid', nullable: true)]
    private ?Uuid $entityId = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $organizationId,
        string $providerKey,
        AiPurpose $purpose,
        AiTier $tier,
        string $model = '',
        int $promptTokens = 0,
        int $completionTokens = 0,
        int $costMicro = 0,
        bool $successful = true,
        int $durationMs = 0,
        ?string $entityType = null,
        ?Uuid $entityId = null,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->providerKey = $providerKey;
        $this->purpose = $purpose;
        $this->tier = $tier;
        $this->model = $model;
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;
        $this->costMicro = $costMicro;
        $this->successful = $successful;
        $this->durationMs = $durationMs;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
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

    public function getProviderKey(): string
    {
        return $this->providerKey;
    }

    public function getPurpose(): AiPurpose
    {
        return $this->purpose;
    }

    public function getTier(): AiTier
    {
        return $this->tier;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    /**
     * Coste de la llamada, en microcéntimos (D-68).
     */
    public function getCostMicro(): int
    {
        return $this->costMicro;
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?Uuid
    {
        return $this->entityId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
