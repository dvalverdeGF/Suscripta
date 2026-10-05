<?php

declare(strict_types=1);

namespace App\Identity\Domain\Repository;

use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Entity\User;
use Symfony\Component\Uid\Uuid;

interface OrganizationRepositoryInterface
{
    public function find(Uuid $id): ?Organization;

    public function findBySlug(string $slug): ?Organization;

    public function slugExists(string $slug): bool;

    /**
     * Organizaciones a las que pertenece un usuario, con su rol.
     *
     * @return list<array{organization: Organization, role: \App\Identity\Domain\Enum\OrganizationRole}>
     */
    public function findForUser(User $user): array;

    public function save(Organization $organization, bool $flush = true): void;
}
