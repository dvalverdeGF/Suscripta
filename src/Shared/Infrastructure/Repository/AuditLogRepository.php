<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Repository;

use App\Shared\Domain\Entity\AuditLog;
use App\Shared\Domain\Repository\AuditLogRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class AuditLogRepository extends DoctrineRepository implements AuditLogRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function findRecent(int $limit = 50): array
    {
        /** @var list<AuditLog> $entries */
        $entries = $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(AuditLog::class, 'a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $entries;
    }

    public function countForOrganization(Uuid $organizationId): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(AuditLog::class, 'a')
            ->where('a.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function purgeOlderThan(DateTimeImmutable $threshold, int $limit = 1000): int
    {
        $ids = $this->scalarIds($this->entityManager
            ->createQueryBuilder()
            ->select('a.id')
            ->from(AuditLog::class, 'a')
            ->where('a.createdAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult());

        if ([] === $ids) {
            return 0;
        }

        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(AuditLog::class, 'a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}
