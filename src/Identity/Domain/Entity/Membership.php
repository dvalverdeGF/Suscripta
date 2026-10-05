<?php

declare(strict_types=1);

namespace App\Identity\Domain\Entity;

use App\Identity\Domain\Enum\OrganizationRole;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Pertenencia de un usuario a una organización.
 *
 * No implementa TenantAwareInterface a propósito: es la tabla que *define* la
 * tenencia, y hay que poder consultarla sin filtro para resolver a qué
 * organizaciones pertenece un usuario al iniciar sesión.
 */
#[ORM\Entity]
#[ORM\Table(name: 'membership')]
#[ORM\UniqueConstraint(name: 'uniq_membership_user_org', columns: ['user_id', 'organization_id'])]
class Membership
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(name: 'organization_id', nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: OrganizationRole::class)]
    private OrganizationRole $role;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(User $user, Organization $organization, OrganizationRole $role)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->organization = $organization;
        $this->role = $role;
        $this->createdAt = new DateTimeImmutable();

        $user->addMembership($this);
        $organization->addMembership($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getRole(): OrganizationRole
    {
        return $this->role;
    }

    public function changeRole(OrganizationRole $role): void
    {
        $this->role = $role;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
