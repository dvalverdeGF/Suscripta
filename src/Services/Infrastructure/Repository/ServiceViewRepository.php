<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Repository;

use App\Services\Domain\Dto\ServiceView;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Implementación Doctrine del read model de servicios.
 *
 * Reutiliza la consulta del `ServiceRepository` y convierte cada entidad en su
 * vista al final. La conversión es la **frontera del módulo**: a partir de
 * aquí nadie fuera de `Services` ve la entidad.
 */
final class ServiceViewRepository extends DoctrineRepository implements ServiceViewRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function findAll(ServiceFilters $filters = new ServiceFilters()): array
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

        return $this->toViews($services);
    }

    public function findOne(Uuid $id): ?ServiceView
    {
        $service = $this->entityManager->find(Service::class, $id);

        return null === $service ? null : ServiceView::fromService($service);
    }

    public function findUpcomingCharges(DateTimeImmutable $until, int $limit = 10): array
    {
        $qb = $this->entityManager
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
            ->setMaxResults($limit);

        /** @var list<Service> $services */
        $services = $qb->getQuery()->getResult();

        return $this->toViews($services);
    }

    public function findUpcomingRenewals(DateTimeImmutable $until, int $limit = 10): array
    {
        $qb = $this->entityManager
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
            ->setMaxResults($limit);

        /** @var list<Service> $services */
        $services = $qb->getQuery()->getResult();

        return $this->toViews($services);
    }

    /**
     * @param list<Service> $services
     *
     * @return list<ServiceView>
     */
    private function toViews(array $services): array
    {
        $views = [];

        foreach ($services as $service) {
            $views[] = ServiceView::fromService($service);
        }

        return $views;
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
