<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Repository;

use App\Mailbox\Domain\Entity\MessageProcessingEvent;
use App\Mailbox\Domain\Repository\MessageProcessingEventRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
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
}
