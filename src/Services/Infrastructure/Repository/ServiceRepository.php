<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Repository;

use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * `Service` implementa TenantAwareInterface, así que el filtro `tenant` de
 * Doctrine añade el `organization_id` a todas estas consultas. El aislamiento
 * no depende de que este repositorio se acuerde de filtrar (D-11).
 */
final class ServiceRepository extends DoctrineRepository implements ServiceRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?Service
    {
        return $this->entityManager->find(Service::class, $id);
    }

    public function findForOrganization(ServiceFilters $filters = new ServiceFilters()): array
    {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('s', 'p', 'e')
            ->from(Service::class, 's')
            ->leftJoin('s.prices', 'p')
            ->leftJoin('s.events', 'e');

        if (null !== $filters->status) {
            $qb->andWhere('s.status = :status')->setParameter('status', $filters->status);
        }

        if (null !== $filters->categoryId) {
            $qb->andWhere('s.categoryId = :categoryId')->setParameter('categoryId', $filters->categoryId);
        }

        if (null !== $filters->providerId) {
            $qb->andWhere('s.providerId = :providerId')->setParameter('providerId', $filters->providerId);
        }

        if (null !== $filters->search && '' !== trim($filters->search)) {
            $qb->andWhere('LOWER(s.name) LIKE :search OR LOWER(s.planName) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower(trim($filters->search)).'%');
        }

        $this->applySort($qb, $filters->sort);

        /** @var list<Service> $services */
        $services = $qb->getQuery()->getResult();

        return $services;
    }

    public function findUpcomingCharges(DateTimeImmutable $until, int $limit = 10): array
    {
        /** @var list<Service> $services */
        $services = $this->entityManager
            ->createQueryBuilder()
            ->select('s', 'p')
            ->from(Service::class, 's')
            ->leftJoin('s.prices', 'p')
            ->where('s.status = :status')
            ->andWhere('s.nextChargeAt IS NOT NULL')
            ->andWhere('s.nextChargeAt <= :until')
            ->setParameter('status', ServiceStatus::ACTIVE)
            ->setParameter('until', $until)
            ->orderBy('s.nextChargeAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $services;
    }

    public function findUpcomingRenewals(DateTimeImmutable $until, int $limit = 10): array
    {
        /** @var list<Service> $services */
        $services = $this->entityManager
            ->createQueryBuilder()
            ->select('s', 'p')
            ->from(Service::class, 's')
            ->leftJoin('s.prices', 'p')
            ->where('s.status = :status')
            ->andWhere('s.renewalAt IS NOT NULL')
            ->andWhere('s.renewalAt <= :until')
            ->setParameter('status', ServiceStatus::ACTIVE)
            ->setParameter('until', $until)
            ->orderBy('s.renewalAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $services;
    }

    public function countByStatus(): array
    {
        /** @var list<array{status: ServiceStatus, total: int|string}> $rows */
        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select('s.status AS status', 'COUNT(s.id) AS total')
            ->from(Service::class, 's')
            ->groupBy('s.status')
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['total'];
        }

        return $counts;
    }

    public function countAll(): int
    {
        $count = $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Service::class, 's')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    public function save(Service $service, bool $flush = true): void
    {
        $this->persist($service, $flush);
    }

    public function remove(Service $service, bool $flush = true): void
    {
        $this->delete($service, $flush);
    }

    private function applySort(QueryBuilder $qb, ?string $sort): void
    {
        match ($sort) {
            'name' => $qb->orderBy('s.name', 'ASC'),
            'name_desc' => $qb->orderBy('s.name', 'DESC'),
            'next_charge' => $qb->orderBy('s.nextChargeAt', 'ASC')->addOrderBy('s.name', 'ASC'),
            'created' => $qb->orderBy('s.createdAt', 'DESC'),
            'updated' => $qb->orderBy('s.updatedAt', 'DESC'),
            default => $qb->orderBy('s.name', 'ASC'),
        };
    }
}
