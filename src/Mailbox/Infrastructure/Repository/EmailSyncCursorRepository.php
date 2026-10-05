<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Repository;

use App\Mailbox\Domain\Entity\EmailSyncCursor;
use App\Mailbox\Domain\Repository\EmailSyncCursorRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class EmailSyncCursorRepository extends DoctrineRepository implements EmailSyncCursorRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?EmailSyncCursor
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('c')
            ->from(EmailSyncCursor::class, 'c')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForAccountFolder(Uuid $emailAccountId, string $folder): ?EmailSyncCursor
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('c')
            ->from(EmailSyncCursor::class, 'c')
            ->where('c.emailAccountId = :accountId')
            ->andWhere('c.folder = :folder')
            ->setParameter('accountId', $emailAccountId)
            ->setParameter('folder', $folder)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForAccount(Uuid $emailAccountId): array
    {
        /** @var list<EmailSyncCursor> $cursors */
        $cursors = $this->entityManager
            ->createQueryBuilder()
            ->select('c')
            ->from(EmailSyncCursor::class, 'c')
            ->where('c.emailAccountId = :accountId')
            ->orderBy('c.folder', 'ASC')
            ->setParameter('accountId', $emailAccountId)
            ->getQuery()
            ->getResult();

        return $cursors;
    }

    public function save(EmailSyncCursor $cursor, bool $flush = true): void
    {
        $this->persist($cursor, $flush);
    }

    public function removeForAccount(Uuid $emailAccountId): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(EmailSyncCursor::class, 'c')
            ->where('c.emailAccountId = :accountId')
            ->setParameter('accountId', $emailAccountId)
            ->getQuery()
            ->execute();
    }
}
