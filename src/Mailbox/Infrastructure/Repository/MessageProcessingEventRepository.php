<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Repository;

use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Entity\MessageProcessingEvent;
use App\Mailbox\Domain\Repository\MessageProcessingEventRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class MessageProcessingEventRepository extends DoctrineRepository implements MessageProcessingEventRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function findForMessage(Uuid $emailMessageId): array
    {
        /** @var list<MessageProcessingEvent> $events */
        $events = $this->entityManager
            ->createQueryBuilder()
            ->select('e')
            ->from(MessageProcessingEvent::class, 'e')
            ->where('e.emailMessageId = :messageId')
            ->orderBy('e.occurredAt', 'ASC')
            ->setParameter('messageId', $emailMessageId)
            ->getQuery()
            ->getResult();

        return $events;
    }

    public function save(MessageProcessingEvent $event, bool $flush = true): void
    {
        $this->persist($event, $flush);
    }

    public function removeForAccount(Uuid $emailAccountId): int
    {
        $ids = $this->messageIdsForAccount($emailAccountId);

        if ([] === $ids) {
            return 0;
        }

        return $this->deleteByIds($ids);
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        $ids = $this->scalarIds($this->entityManager
            ->createQueryBuilder()
            ->select('m.id')
            ->from(EmailMessage::class, 'm')
            ->where('m.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->getQuery()
            ->getSingleColumnResult());

        if ([] === $ids) {
            return 0;
        }

        return $this->deleteByIds($ids);
    }

    public function purgeOlderThan(DateTimeImmutable $threshold, int $limit = 500): int
    {
        $ids = $this->scalarIds($this->entityManager
            ->createQueryBuilder()
            ->select('e.id')
            ->from(MessageProcessingEvent::class, 'e')
            ->where('e.occurredAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult());

        if ([] === $ids) {
            return 0;
        }

        return $this->deleteByIds($ids);
    }

    /**
     * @return list<string>
     */
    private function messageIdsForAccount(Uuid $emailAccountId): array
    {
        $ids = $this->scalarIds($this->entityManager
            ->createQueryBuilder()
            ->select('m.id')
            ->from(EmailMessage::class, 'm')
            ->where('m.emailAccountId = :accountId')
            ->setParameter('accountId', $emailAccountId)
            ->getQuery()
            ->getSingleColumnResult());

        return $ids;
    }

    /**
     * @param list<string> $ids
     */
    private function deleteByIds(array $ids): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(MessageProcessingEvent::class, 'e')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}
