<?php

declare(strict_types=1);

namespace App\Subscriptions\Infrastructure\Repository;

use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use App\Subscriptions\Domain\Entity\PlanPrice;
use App\Subscriptions\Domain\Repository\PlanPriceRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class PlanPriceRepository extends DoctrineRepository implements PlanPriceRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?PlanPrice
    {
        return $this->entityManager->find(PlanPrice::class, $id);
    }

    public function findCurrentForPlan(Uuid $planId): array
    {
        /** @var list<PlanPrice> $prices */
        $prices = $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(PlanPrice::class, 'p')
            ->where('IDENTITY(p.plan) = :planId')
            ->andWhere('p.validTo IS NULL')
            ->setParameter('planId', $planId)
            ->orderBy('p.amountMinor', 'ASC')
            ->getQuery()
            ->getResult();

        return $prices;
    }

    public function findHistoryForPlan(Uuid $planId): array
    {
        /** @var list<PlanPrice> $prices */
        $prices = $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(PlanPrice::class, 'p')
            ->where('IDENTITY(p.plan) = :planId')
            ->andWhere('p.validTo IS NOT NULL')
            ->setParameter('planId', $planId)
            ->orderBy('p.validFrom', 'DESC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $prices;
    }

    public function findCurrent(Uuid $planId, BillingPeriod $billingPeriod, Currency $currency): ?PlanPrice
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(PlanPrice::class, 'p')
            ->where('IDENTITY(p.plan) = :planId')
            ->andWhere('p.validTo IS NULL')
            ->andWhere('p.billingPeriod = :period')
            ->andWhere('p.currency = :currency')
            ->setParameter('planId', $planId)
            ->setParameter('period', $billingPeriod)
            ->setParameter('currency', $currency)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(PlanPrice $price): void
    {
        $this->persist($price, true);
    }

    public function remove(PlanPrice $price): void
    {
        $this->delete($price, true);
    }
}
