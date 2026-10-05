<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Entity;

use App\Catalog\Domain\Enum\ProviderIdentitySource;
use App\Catalog\Domain\Enum\ProviderIdentityType;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Cómo reconocemos a un proveedor en un correo.
 *
 * Un mismo proveedor puede facturar desde varios dominios y direcciones, y
 * puede cambiar de asunto. Cada forma de reconocerlo es una fila, con su propia
 * confianza y su propio origen (`seed`, `learned`, `user`).
 *
 * `UNIQUE (type, value)` es global: un dominio pertenece a un solo proveedor.
 */
#[ORM\Entity]
#[ORM\Table(name: 'provider_identity')]
#[ORM\UniqueConstraint(name: 'uniq_provider_identity_type_value', columns: ['type', 'value'])]
#[ORM\Index(name: 'idx_provider_identity_provider', columns: ['provider_id'])]
class ProviderIdentity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Provider::class)]
    #[ORM\JoinColumn(name: 'provider_id', nullable: false, onDelete: 'CASCADE')]
    private Provider $provider;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: ProviderIdentityType::class)]
    private ProviderIdentityType $type;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $value;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $confidence = 100;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ProviderIdentitySource::class)]
    private ProviderIdentitySource $source;

    #[ORM\Column(name: 'hit_count', type: Types::INTEGER)]
    private int $hitCount = 0;

    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Provider $provider,
        ProviderIdentityType $type,
        string $value,
        ProviderIdentitySource $source = ProviderIdentitySource::SEED,
        int $confidence = 100,
    ) {
        $this->id = Uuid::v7();
        $this->provider = $provider;
        $this->type = $type;
        $this->value = $type->isPattern() ? trim($value) : mb_strtolower(trim($value));
        $this->source = $source;
        $this->confidence = max(0, min(100, $confidence));
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProvider(): Provider
    {
        return $this->provider;
    }

    public function getType(): ProviderIdentityType
    {
        return $this->type;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getConfidence(): int
    {
        return $this->confidence;
    }

    public function getSource(): ProviderIdentitySource
    {
        return $this->source;
    }

    public function getHitCount(): int
    {
        return $this->hitCount;
    }

    public function getLastSeenAt(): ?DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    /**
     * Refuerza la confianza cuando la identidad vuelve a acertar. Nunca baja
     * sola: para eso está la corrección explícita del usuario.
     */
    public function recordHit(DateTimeImmutable $at): void
    {
        ++$this->hitCount;
        $this->lastSeenAt = $at;
        $this->confidence = min(100, $this->confidence + 1);
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
