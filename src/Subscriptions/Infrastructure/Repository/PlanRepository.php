<?php

declare(strict_types=1);

namespace App\Subscriptions\Infrastructure\Repository;

use App\Identity\Domain\Enum\OrganizationPlan;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use App\Subscriptions\Domain\Entity\Plan;
use App\Subscriptions\Domain\Repository\PlanRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * El catálogo es global: no implementa `TenantAwareInterface` y el filtro
 * `tenant` de Doctrine no le añade ninguna condición. Es intencionado (D-80).
 */
final class PlanRepository extends DoctrineRepository implements PlanRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?Plan
    {
        return $this->entityManager->find(Plan::class, $id);
    }

    public function findByCode(OrganizationPlan $code): ?Plan
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(Plan::class, 'p')
            ->where('p.code = :code')
            ->setParameter('code', $code)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAll(bool $onlyActive = false): array
    {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(Plan::class, 'p')
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.name', 'ASC');

        if ($onlyActive) {
            $qb->where('p.isActive = true');
        }

        /** @var list<Plan> $plans */
        $plans = $qb->getQuery()->getResult();

        return $plans;
    }

    public function nextPosition(): int
    {
        $max = $this->entityManager
            ->createQueryBuilder()
            ->select('MAX(p.position)')
            ->from(Plan::class, 'p')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }

    public function save(Plan $plan): void
    {
        $this->persist($plan, true);
    }

    public function remove(Plan $plan): void
    {
        $this->delete($plan, true);
    }
}
