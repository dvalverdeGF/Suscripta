<?php

declare(strict_types=1);

namespace App\Processing\Infrastructure\Repository;

use App\Processing\Domain\Entity\ExtractionCache;
use App\Processing\Domain\Repository\ExtractionCacheRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class ExtractionCacheRepository extends DoctrineRepository implements ExtractionCacheRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function findForContentHash(string $contentHash): ?ExtractionCache
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('c')
            ->from(ExtractionCache::class, 'c')
            ->where('c.contentHash = :hash')
            ->setParameter('hash', $contentHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(ExtractionCache $cache, bool $flush = true): void
    {
        $this->persist($cache, $flush);
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(ExtractionCache::class, 'c')
            ->where('c.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->getQuery()
            ->execute();
    }

    public function countForOrganization(): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(ExtractionCache::class, 'c')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findStaleBefore(DateTimeImmutable $threshold, int $limit = 500): array
    {
        /** @var list<ExtractionCache> $entries */
        $entries = $this->entityManager
            ->createQueryBuilder()
            ->select('c')
            ->from(ExtractionCache::class, 'c')
            ->where('c.lastUsedAt < :threshold')
            ->orderBy('c.lastUsedAt', 'ASC')
            ->setParameter('threshold', $threshold)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $entries;
    }
}
