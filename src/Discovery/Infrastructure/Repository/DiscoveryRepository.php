<?php

declare(strict_types=1);

namespace App\Discovery\Infrastructure\Repository;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Entity\DiscoveryEvidence;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DiscoveryRepository extends DoctrineRepository implements DiscoveryRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?Discovery
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(Discovery::class, 'd')
            ->where('d.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findPending(int $limit = 50): array
    {
        return $this->findForOrganization(DiscoveryStatus::PENDING, $limit);
    }

    public function findForOrganization(
        ?DiscoveryStatus $status = null,
        int $limit = 100,
        ?DiscoveryType $type = null,
    ): array {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(Discovery::class, 'd')
            ->orderBy('d.confidence', 'DESC')
            ->addOrderBy('d.detectedAt', 'DESC')
            ->setMaxResults($limit);

        if (null !== $status) {
            $qb->andWhere('d.status = :status')->setParameter('status', $status);
        }

        if (null !== $type) {
            $qb->andWhere('d.type = :type')->setParameter('type', $type);
        }

        /** @var list<Discovery> $discoveries */
        $discoveries = $qb->getQuery()->getResult();

        return $discoveries;
    }

    public function findBlockingByDedupKey(string $dedupKey, DateTimeImmutable $ignoredSince): ?Discovery
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(Discovery::class, 'd')
            ->where('d.dedupKey = :key')
            ->andWhere('(d.status IN (:open) OR (d.status = :ignored AND d.reviewedAt >= :since))')
            ->setParameter('key', $dedupKey)
            ->setParameter('open', [DiscoveryStatus::PENDING])
            ->setParameter('ignored', DiscoveryStatus::IGNORED)
            ->setParameter('since', $ignoredSince)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByDedupKey(string $dedupKey): ?Discovery
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(Discovery::class, 'd')
            ->where('d.dedupKey = :key')
            ->setParameter('key', $dedupKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findExpired(DateTimeImmutable $before, int $limit = 100): array
    {
        /** @var list<Discovery> $discoveries */
        $discoveries = $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(Discovery::class, 'd')
            ->where('d.status = :status')
            ->andWhere('d.detectedAt <= :before')
            ->setParameter('status', DiscoveryStatus::PENDING)
            ->setParameter('before', $before)
            ->orderBy('d.detectedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $discoveries;
    }

    public function countPending(): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(Discovery::class, 'd')
            ->where('d.status = :status')
            ->setParameter('status', DiscoveryStatus::PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByStatus(): array
    {
        /** @var list<array{status: DiscoveryStatus, total: int|string}> $rows */
        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select('d.status AS status', 'COUNT(d.id) AS total')
            ->from(Discovery::class, 'd')
            ->groupBy('d.status')
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['total'];
        }

        return $counts;
    }

    public function save(Discovery $discovery, bool $flush = true): void
    {
        $this->persist($discovery, $flush);
    }

    public function flush(): void
    {
        parent::flush();
    }

    public function saveEvidence(DiscoveryEvidence $evidence, bool $flush = true): void
    {
        $this->persist($evidence, $flush);
    }

    public function findEvidence(Uuid $discoveryId): array
    {
        /** @var list<DiscoveryEvidence> $evidence */
        $evidence = $this->entityManager
            ->createQueryBuilder()
            ->select('e')
            ->from(DiscoveryEvidence::class, 'e')
            ->where('e.discoveryId = :id')
            ->setParameter('id', $discoveryId)
            ->orderBy('e.weight', 'DESC')
            ->getQuery()
            ->getResult();

        return $evidence;
    }
}
