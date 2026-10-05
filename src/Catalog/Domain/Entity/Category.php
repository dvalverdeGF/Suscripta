<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Categoría de gasto (Software, Hosting, Telecomunicaciones…).
 *
 * `organizationId = null` identifica una categoría del catálogo global; con
 * valor, es una categoría propia del tenant. El catálogo global es de solo
 * lectura para el usuario: puede crear las suyas, no reescribir las comunes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'category')]
#[ORM\UniqueConstraint(name: 'uniq_category_org_slug', columns: ['organization_id', 'slug'])]
class Category
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid', nullable: true)]
    private ?Uuid $organizationId;

    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $slug;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $color = '#64748b';

    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(name: 'is_system', type: Types::BOOLEAN)]
    private bool $system;

    #[ORM\Column(name: 'sort_order', type: Types::SMALLINT)]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $name,
        string $slug,
        ?Uuid $organizationId = null,
        bool $system = false,
        string $color = '#64748b',
        ?string $icon = null,
        int $sortOrder = 0,
    ) {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->slug = $slug;
        $this->organizationId = $organizationId;
        $this->system = $system;
        $this->color = $color;
        $this->icon = $icon;
        $this->sortOrder = $sortOrder;
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

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): void
    {
        $this->color = $color;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
