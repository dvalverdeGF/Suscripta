<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\Entity\Membership;
use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class OrganizationRepository extends DoctrineRepository implements OrganizationRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?Organization
    {
        return $this->entityManager->find(Organization::class, $id);
    }

    public function findBySlug(string $slug): ?Organization
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('o')
            ->from(Organization::class, 'o')
            ->where('o.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function slugExists(string $slug): bool
    {
        $count = $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Organization::class, 'o')
            ->where('o.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function findForUser(User $user): array
    {
        /** @var list<Membership> $memberships */
        $memberships = $this->entityManager
            ->createQueryBuilder()
            ->select('m', 'o')
            ->from(Membership::class, 'm')
            ->join('m.organization', 'o')
            ->where('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (Membership $membership): array => [
                'organization' => $membership->getOrganization(),
                'role' => $membership->getRole(),
            ],
            $memberships,
        );
    }

    public function save(Organization $organization, bool $flush = true): void
    {
        $this->persist($organization, $flush);
    }
}
