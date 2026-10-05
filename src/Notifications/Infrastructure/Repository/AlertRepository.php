<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Repository;

use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\AlertStatus;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Repository\AlertRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class AlertRepository extends DoctrineRepository implements AlertRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?Alert
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(Alert::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForOrganization(?AlertStatus $status = null, int $limit = 100): array
    {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(Alert::class, 'a')
            ->orderBy('a.dueAt', 'ASC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit);

        if (null !== $status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }

        /** @var list<Alert> $alerts */
        $alerts = $qb->getQuery()->getResult();

        return $alerts;
    }

    public function findOpen(int $limit = 50): array
    {
        /** @var list<Alert> $alerts */
        $alerts = $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(Alert::class, 'a')
            ->where('a.status = :status')
            ->setParameter('status', AlertStatus::OPEN)
            ->orderBy('a.dueAt', 'ASC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $alerts;
    }

    public function findByDedupKey(string $dedupKey): ?Alert
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(Alert::class, 'a')
            ->where('a.dedupKey = :key')
            ->setParameter('key', $dedupKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOpenByType(AlertType $type): array
    {
        /** @var list<Alert> $alerts */
        $alerts = $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(Alert::class, 'a')
            ->where('a.type = :type')
            ->andWhere('a.status = :status')
            ->setParameter('type', $type)
            ->setParameter('status', AlertStatus::OPEN)
            ->getQuery()
            ->getResult();

        return $alerts;
    }

    public function findOpenForService(Uuid $serviceId): array
    {
        /** @var list<Alert> $alerts */
        $alerts = $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(Alert::class, 'a')
            ->where('a.serviceId = :service')
            ->andWhere('a.status = :status')
            ->setParameter('service', $serviceId)
            ->setParameter('status', AlertStatus::OPEN)
            ->getQuery()
            ->getResult();

        return $alerts;
    }

    public function countOpen(): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Alert::class, 'a')
            ->where('a.status = :status')
            ->setParameter('status', AlertStatus::OPEN)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByStatus(): array
    {
        /** @var list<array{status: AlertStatus, total: int|string}> $rows */
        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select('a.status AS status', 'COUNT(a.id) AS total')
            ->from(Alert::class, 'a')
            ->groupBy('a.status')
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['total'];
        }

        return $counts;
    }

    public function save(Alert $alert, bool $flush = true): void
    {
        $this->persist($alert, $flush);
    }

    public function remove(Alert $alert, bool $flush = true): void
    {
        $this->delete($alert, $flush);
    }

    public function flush(): void
    {
        parent::flush();
    }
}
