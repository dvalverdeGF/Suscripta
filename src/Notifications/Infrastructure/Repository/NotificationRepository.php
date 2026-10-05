<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Repository;

use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class NotificationRepository extends DoctrineRepository implements NotificationRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?Notification
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('n')
            ->from(Notification::class, 'n')
            ->where('n.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByDedupKey(string $dedupKey): ?Notification
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('n')
            ->from(Notification::class, 'n')
            ->where('n.dedupKey = :key')
            ->setParameter('key', $dedupKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForAlert(Uuid $alertId): array
    {
        /** @var list<Notification> $notifications */
        $notifications = $this->entityManager
            ->createQueryBuilder()
            ->select('n')
            ->from(Notification::class, 'n')
            ->where('n.alertId = :alert')
            ->setParameter('alert', $alertId)
            ->orderBy('n.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $notifications;
    }

    public function findRecent(int $limit = 50): array
    {
        /** @var list<Notification> $notifications */
        $notifications = $this->entityManager
            ->createQueryBuilder()
            ->select('n')
            ->from(Notification::class, 'n')
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $notifications;
    }

    public function save(Notification $notification, bool $flush = true): void
    {
        $this->persist($notification, $flush);
    }
}
