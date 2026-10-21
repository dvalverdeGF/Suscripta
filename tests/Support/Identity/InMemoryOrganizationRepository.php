<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Enum\OrganizationRole;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Repositorio de organizaciones en memoria.
 *
 * Guarda las mismas instancias que recibe, de modo que los ajustes escritos
 * (`Organization.settings`) se leen de vuelta tal cual.
 */
final class InMemoryOrganizationRepository implements OrganizationRepositoryInterface
{
    /**
     * @var array<string, Organization>
     */
    private array $organizations = [];

    public function add(Organization $organization): void
    {
        $this->organizations[$organization->getId()->toRfc4122()] = $organization;
    }

    public function find(Uuid $id): ?Organization
    {
        return $this->organizations[$id->toRfc4122()] ?? null;
    }

    public function findBySlug(string $slug): ?Organization
    {
        foreach ($this->organizations as $organization) {
            if ($organization->getSlug() === $slug) {
                return $organization;
            }
        }

        return null;
    }

    public function slugExists(string $slug): bool
    {
        return null !== $this->findBySlug($slug);
    }

    /**
     * @return list<array{organization: Organization, role: OrganizationRole}>
     */
    public function findForUser(User $user): array
    {
        return [];
    }

    /**
     * @return list<Organization>
     */
    public function findAll(): array
    {
        return array_values($this->organizations);
    }

    /**
     * @return list<array{user: User, role: OrganizationRole}>
     */
    public function findMembers(Uuid $organizationId): array
    {
        return [];
    }

    public function save(Organization $organization, bool $flush = true): void
    {
        $this->organizations[$organization->getId()->toRfc4122()] = $organization;
    }
}
