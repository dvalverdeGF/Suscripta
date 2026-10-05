<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Repository;

use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class EmailMessageRepository extends DoctrineRepository implements EmailMessageRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?EmailMessage
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('m')
            ->from(EmailMessage::class, 'm')
            ->where('m.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByAccountFolderUid(Uuid $emailAccountId, string $folder, int $uid): ?EmailMessage
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('m')
            ->from(EmailMessage::class, 'm')
            ->where('m.emailAccountId = :accountId')
            ->andWhere('m.folder = :folder')
            ->andWhere('m.uid = :uid')
            ->setParameter('accountId', $emailAccountId)
            ->setParameter('folder', $folder)
            ->setParameter('uid', $uid)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByAccountAndMessageId(Uuid $emailAccountId, string $messageId): ?EmailMessage
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('m')
            ->from(EmailMessage::class, 'm')
            ->where('m.emailAccountId = :accountId')
            ->andWhere('m.messageId = :messageId')
            ->setParameter('accountId', $emailAccountId)
            ->setParameter('messageId', $messageId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByContentHash(string $contentHash): ?EmailMessage
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('m')
            ->from(EmailMessage::class, 'm')
            ->where('m.contentHash = :hash')
            ->andWhere('m.extractionTier IS NOT NULL')
            ->setParameter('hash', $contentHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForOrganization(?MessageProcessingState $state = null, int $limit = 50): array
    {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('m')
            ->from(EmailMessage::class, 'm')
            ->orderBy('m.receivedAt', 'DESC')
            ->setMaxResults($limit);

        if (null !== $state) {
            $qb->andWhere('m.processingState = :state')->setParameter('state', $state);
        }

        /** @var list<EmailMessage> $messages */
        $messages = $qb->getQuery()->getResult();

        return $messages;
    }

    public function findPendingForAccount(Uuid $emailAccountId, int $limit = 200): array
    {
        /** @var list<EmailMessage> $messages */
        $messages = $this->entityManager
            ->createQueryBuilder()
            ->select('m')
            ->from(EmailMessage::class, 'm')
            ->where('m.emailAccountId = :accountId')
            ->andWhere('m.processingState IN (:states)')
            ->setParameter('accountId', $emailAccountId)
            ->setParameter('states', MessageProcessingState::pendingValues())
            ->orderBy('m.receivedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $messages;
    }

    public function countByState(): array
    {
        /** @var list<array{state: MessageProcessingState, total: int|string}> $rows */
        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select('m.processingState AS state', 'COUNT(m.id) AS total')
            ->from(EmailMessage::class, 'm')
            ->groupBy('m.processingState')
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['state']->value] = (int) $row['total'];
        }

        return $counts;
    }

    public function countForOrganization(): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(EmailMessage::class, 'm')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countForAccount(Uuid $emailAccountId): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(EmailMessage::class, 'm')
            ->where('m.emailAccountId = :accountId')
            ->setParameter('accountId', $emailAccountId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(EmailMessage $message, bool $flush = true): void
    {
        $this->persist($message, $flush);
    }

    public function saveAll(iterable $messages): void
    {
        foreach ($messages as $message) {
            $this->persist($message);
        }

        $this->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function removeForAccount(Uuid $emailAccountId): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(EmailMessage::class, 'm')
            ->where('m.emailAccountId = :accountId')
            ->setParameter('accountId', $emailAccountId)
            ->getQuery()
            ->execute();
    }
}
