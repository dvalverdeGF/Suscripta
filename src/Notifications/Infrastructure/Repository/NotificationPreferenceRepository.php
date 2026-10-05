<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Repository;

use App\Notifications\Domain\Entity\NotificationPreference;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Enum\NotificationChannel;
use App\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;

use Symfony\Component\Uid\Uuid;

final class NotificationPreferenceRepository extends DoctrineRepository implements NotificationPreferenceRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?NotificationPreference
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(NotificationPreference::class, 'p')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOne(Uuid $userId, AlertType $type, NotificationChannel $channel): ?NotificationPreference
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(NotificationPreference::class, 'p')
            ->where('p.userId = :user')
            ->andWhere('p.alertType = :type')
            ->andWhere('p.channel = :channel')
            ->setParameter('user', $userId)
            ->setParameter('type', $type)
            ->setParameter('channel', $channel)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForUser(Uuid $userId): array
    {
        /** @var list<NotificationPreference> $preferences */
        $preferences = $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(NotificationPreference::class, 'p')
            ->where('p.userId = :user')
            ->setParameter('user', $userId)
            ->orderBy('p.alertType', 'ASC')
            ->addOrderBy('p.channel', 'ASC')
            ->getQuery()
            ->getResult();

        return $preferences;
    }

    public function findEnabledMapForUser(Uuid $userId): array
    {
        $map = [];

        foreach ($this->findForUser($userId) as $preference) {
            $map[sprintf('%s|%s', $preference->getAlertType()->value, $preference->getChannel()->value)] = $preference->isEnabled();
        }

        return $map;
    }

    public function save(NotificationPreference $preference, bool $flush = true): void
    {
        $this->persist($preference, $flush);
    }

    public function remove(NotificationPreference $preference, bool $flush = true): void
    {
        $this->delete($preference, $flush);
    }
}
