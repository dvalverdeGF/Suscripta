<?php

declare(strict_types=1);

namespace App\Subscriptions\Infrastructure\Repository;

use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use App\Subscriptions\Domain\Entity\Subscription;
use App\Subscriptions\Domain\Enum\SubscriptionStatus;
use App\Subscriptions\Domain\Repository\SubscriptionFilters;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

use function mb_strtolower;

use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * `Subscription` implementa TenantAwareInterface, así que el filtro `tenant` de
 * Doctrine añade el `organization_id` a todas estas consultas. El aislamiento
 * no depende de que este repositorio se acuerde de filtrar (D-11).
 */
final class SubscriptionRepository extends DoctrineRepository implements SubscriptionRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?Subscription
    {
        return $this->entityManager->find(Subscription::class, $id);
    }

    public function findForOrganization(SubscriptionFilters $filters = new SubscriptionFilters()): array
    {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's');

        if (null !== $filters->status) {
            $qb->andWhere('s.status = :status')->setParameter('status', $filters->status);
        }

        if (null !== $filters->planId) {
            $qb->andWhere('s.planId = :planId')->setParameter('planId', $filters->planId);
        }

        if (null !== $filters->search && '' !== trim($filters->search)) {
            $qb->andWhere('LOWER(s.name) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower(trim($filters->search)).'%');
        }

        match ($filters->normalizedSort()) {
            'name' => $qb->orderBy('s.name', 'ASC'),
            'created' => $qb->orderBy('s.createdAt', 'DESC'),
            default => $qb->orderBy('s.currentPeriodEnd', 'ASC'),
        };

        /** @var list<Subscription> $subscriptions */
        $subscriptions = $qb->getQuery()->getResult();

        return $subscriptions;
    }

    public function findLiveForService(Uuid $serviceId): ?Subscription
    {
        /** @var list<Subscription> $subscriptions */
        $subscriptions = $this->entityManager
            ->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's')
            ->where('s.serviceId = :serviceId')
            ->andWhere('s.status IN (:live)')
            ->setParameter('serviceId', $serviceId)
            ->setParameter('live', ['trialing', 'active', 'past_due'])
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $subscriptions[0] ?? null;
    }

    public function countByStatus(): array
    {
        /** @var list<array{status: SubscriptionStatus, total: int|string}> $rows */
        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select('s.status AS status', 'COUNT(s.id) AS total')
            ->from(Subscription::class, 's')
            ->groupBy('s.status')
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['total'];
        }

        return $counts;
    }

    public function findWithoutPlan(): array
    {
        /** @var list<Subscription> $subscriptions */
        $subscriptions = $this->entityManager
            ->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's')
            ->where('s.planId IS NULL')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $subscriptions;
    }

    public function findByExternalReference(string $externalReference): ?Subscription
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's')
            ->where('s.externalReference = :reference')
            ->setParameter('reference', $externalReference)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findBillable(): array
    {
        /** @var list<Subscription> $subscriptions */
        $subscriptions = $this->entityManager
            ->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's')
            ->where('s.externalReference IS NOT NULL')
            ->andWhere('s.status != :canceled')
            ->setParameter('canceled', SubscriptionStatus::CANCELED)
            ->orderBy('s.currentPeriodEnd', 'ASC')
            ->getQuery()
            ->getResult();

        return $subscriptions;
    }

    public function save(Subscription $subscription): void
    {
        $this->persist($subscription, true);
    }

    public function remove(Subscription $subscription): void
    {
        $this->delete($subscription, true);
    }
}
