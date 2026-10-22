<?php

declare(strict_types=1);

namespace App\Integrations\Infrastructure\Repository;

use App\Integrations\Domain\Entity\Integration;
use App\Integrations\Domain\Repository\IntegrationRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class IntegrationRepository extends DoctrineRepository implements IntegrationRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(string $key): ?Integration
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(Integration::class, 'i')
            ->where('i.key = :key')
            ->setParameter('key', $key)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function all(): array
    {
        /** @var list<Integration> $integrations */
        $integrations = $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(Integration::class, 'i')
            ->orderBy('i.key', 'ASC')
            ->getQuery()
            ->getResult();

        return $integrations;
    }

    public function enabled(): array
    {
        /** @var list<Integration> $integrations */
        $integrations = $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(Integration::class, 'i')
            ->where('i.enabled = true')
            ->orderBy('i.key', 'ASC')
            ->getQuery()
            ->getResult();

        return $integrations;
    }

    public function save(Integration $integration, bool $flush = true): void
    {
        $this->persist($integration, $flush);
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(Integration::class, 'i')
            ->where('i.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->getQuery()
            ->execute();
    }

    public function findForOrganization(Uuid $organizationId): array
    {
        /** @var list<Integration> $integrations */
        $integrations = $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(Integration::class, 'i')
            ->where('i.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('i.key', 'ASC')
            ->getQuery()
            ->getResult();

        return $integrations;
    }
}
