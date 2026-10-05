<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Cómo extraemos los datos de un proveedor.
 *
 * `key` referencia un parser de código (`ovh`, `github`, `microsoft`); `config`
 * permite patrones declarativos sin tocar código. Un proveedor puede tener
 * varios parsers y el sistema elige el que mejor encaje con el documento.
 *
 * Los contadores de éxito y fallo permiten desactivar automáticamente un parser
 * que ha dejado de funcionar porque el proveedor cambió su plantilla.
 */
#[ORM\Entity]
#[ORM\Table(name: 'provider_parser')]
#[ORM\UniqueConstraint(name: 'uniq_provider_parser_key_version', columns: ['provider_id', 'key', 'version'])]
class ProviderParser
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Provider::class)]
    #[ORM\JoinColumn(name: 'provider_id', nullable: false, onDelete: 'CASCADE')]
    private Provider $provider;

    #[ORM\Column(type: Types::STRING, length: 60)]
    private string $key;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $version = 1;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $config = [];

    #[ORM\Column(name: 'success_count', type: Types::INTEGER)]
    private int $successCount = 0;

    #[ORM\Column(name: 'failure_count', type: Types::INTEGER)]
    private int $failureCount = 0;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $config */
    public function __construct(Provider $provider, string $key, int $version = 1, array $config = [])
    {
        $this->id = Uuid::v7();
        $this->provider = $provider;
        $this->key = $key;
        $this->version = $version;
        $this->config = $config;
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

    public function getKey(): string
    {
        return $this->key;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return $this->config;
    }

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function recordSuccess(DateTimeImmutable $at): void
    {
        ++$this->successCount;
        $this->lastUsedAt = $at;
    }

    /**
     * Tras cinco fallos consecutivos el parser se desactiva solo: es más
     * honesto dejar de intentarlo que gastar IA en cada factura futura.
     */
    public function recordFailure(DateTimeImmutable $at): void
    {
        ++$this->failureCount;
        $this->lastUsedAt = $at;

        if ($this->failureCount >= 5 && 0 === $this->successCount) {
            $this->enabled = false;
        }
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
