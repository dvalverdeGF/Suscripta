<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Proveedor de un servicio (OVH, Microsoft, GitHub, una aseguradora…).
 *
 * `organizationId = null` → catálogo global compartido. Con valor → proveedor
 * propio del tenant, creado a mano o aprendido del buzón.
 *
 * `Provider` responde *quién es*; `ProviderIdentity` responde *cómo lo
 * reconocemos*; `ProviderParser` responde *cómo extraemos sus datos*
 * (ARCHITECTURE.md §4.2).
 */
#[ORM\Entity]
#[ORM\Table(name: 'provider')]
#[ORM\UniqueConstraint(name: 'uniq_provider_org_slug', columns: ['organization_id', 'slug'])]
class Provider
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid', nullable: true)]
    private ?Uuid $organizationId;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $slug;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $aliases = [];

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(name: 'logo_path', type: Types::STRING, length: 255, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column(name: 'default_category_id', type: 'uuid', nullable: true)]
    private ?Uuid $defaultCategoryId = null;

    #[ORM\Column(name: 'is_system', type: Types::BOOLEAN)]
    private bool $system;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $name,
        string $slug,
        ?Uuid $organizationId = null,
        bool $system = false,
    ) {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->slug = $slug;
        $this->organizationId = $organizationId;
        $this->system = $system;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrganizationId(): ?Uuid
    {
        return $this->organizationId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    /** @return list<string> */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    /** @param list<string> $aliases */
    public function setAliases(array $aliases): void
    {
        $this->aliases = array_values(array_unique($aliases));
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): void
    {
        $this->website = $website;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): void
    {
        $this->logoPath = $logoPath;
    }

    public function getDefaultCategoryId(): ?Uuid
    {
        return $this->defaultCategoryId;
    }

    public function setDefaultCategoryId(?Uuid $defaultCategoryId): void
    {
        $this->defaultCategoryId = $defaultCategoryId;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
