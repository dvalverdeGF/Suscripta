<?php

declare(strict_types=1);

namespace App\Subscriptions\Infrastructure\Repository;

use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use App\Subscriptions\Domain\Entity\PlanFeature;
use App\Subscriptions\Domain\Repository\PlanFeatureRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class PlanFeatureRepository extends DoctrineRepository implements PlanFeatureRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?PlanFeature
    {
        return $this->entityManager->find(PlanFeature::class, $id);
    }

    public function findForPlan(Uuid $planId): array
    {
        /** @var list<PlanFeature> $features */
        $features = $this->entityManager
            ->createQueryBuilder()
            ->select('f')
            ->from(PlanFeature::class, 'f')
            ->where('IDENTITY(f.plan) = :planId')
            ->setParameter('planId', $planId)
            ->orderBy('f.position', 'ASC')
            ->addOrderBy('f.label', 'ASC')
            ->getQuery()
            ->getResult();

        return $features;
    }

    public function nextPosition(Uuid $planId): int
    {
        $max = $this->entityManager
            ->createQueryBuilder()
            ->select('MAX(f.position)')
            ->from(PlanFeature::class, 'f')
            ->where('IDENTITY(f.plan) = :planId')
            ->setParameter('planId', $planId)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }

    public function save(PlanFeature $feature): void
    {
        $this->persist($feature, true);
    }

    public function remove(PlanFeature $feature): void
    {
        $this->delete($feature, true);
    }
}
