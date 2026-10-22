<?php

declare(strict_types=1);

namespace App\Identity\Domain\Entity;

use App\Identity\Domain\Enum\OrganizationPlan;
use App\Identity\Domain\Enum\OrganizationRole;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Organización: la unidad de aislamiento de datos (DECISIONS.md D-02).
 *
 * Existe desde la v1 aunque el producto se venda a una sola persona: un
 * autónomo puede tener una sociedad, y migrar después de "usuario" a
 * "organización" obligaría a reescribir todo el modelo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'organization')]
#[ORM\UniqueConstraint(name: 'uniq_organization_slug', columns: ['slug'])]
class Organization
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $slug;

    #[ORM\Column(name: 'is_personal', type: Types::BOOLEAN)]
    private bool $personal = false;

    /**
     * Plan contratado (Fase 17).
     *
     * El plan decide los límites de la organización: cuántos servicios, buzones
     * y documentos puede tener y si puede usar inteligencia artificial. Los
     * números de cada plan viven en `config/packages/entitlements.yaml`, no
     * aquí: aquí solo se guarda **qué** plan es (D-64).
     */
    // El valor por defecto de la columna es «free» en la base de datos: se
    // declara aquí para que el esquema y el mapeo no diverjan (si divergen,
    // cada `migrations:diff` propone un `ALTER TABLE` que no aporta nada).
    #[ORM\Column(name: 'plan', type: Types::STRING, length: 20, enumType: OrganizationPlan::class, options: ['default' => OrganizationPlan::FREE->value])]
    private OrganizationPlan $plan = OrganizationPlan::FREE;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $settings = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, Membership> */
    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'organization', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $memberships;

    public function __construct(string $name, string $slug, bool $personal = false)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->slug = $slug;
        $this->personal = $personal;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->memberships = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function isPersonal(): bool
    {
        return $this->personal;
    }

    /**
     * Plan contratado por la organización.
     */
    public function getPlan(): OrganizationPlan
    {
        return $this->plan;
    }

    /**
     * Cambia el plan de la organización.
     *
     * El cambio se hace desde el backoffice por una persona con permiso
     * (D-65): es la operación que hace que una organización pase de un plan a
     * otro, y por eso queda registrada en el libro de auditoría.
     */
    public function changePlan(OrganizationPlan $plan): void
    {
        $this->plan = $plan;
        $this->touch();
    }

    /** @return array<string, mixed> */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /** @param array<string, mixed> $settings */
    public function updateSettings(array $settings): void
    {
        $this->settings = $settings;
        $this->touch();
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function setSetting(string $key, mixed $value): void
    {
        $this->settings[$key] = $value;
        $this->touch();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, Membership> */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function addMembership(Membership $membership): void
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
        }
    }

    public function roleOf(User $user): ?OrganizationRole
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getUser() === $user) {
                return $membership->getRole();
            }
        }

        return null;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
