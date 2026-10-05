<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Repository;

use App\Mailbox\Domain\Entity\EmailSyncRun;
use App\Mailbox\Domain\Repository\EmailSyncRunRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class EmailSyncRunRepository extends DoctrineRepository implements EmailSyncRunRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?EmailSyncRun
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('r')
            ->from(EmailSyncRun::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestForAccount(Uuid $emailAccountId): ?EmailSyncRun
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('r')
            ->from(EmailSyncRun::class, 'r')
            ->where('r.emailAccountId = :accountId')
            ->orderBy('r.startedAt', 'DESC')
            ->setParameter('accountId', $emailAccountId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findRecentForAccount(Uuid $emailAccountId, int $limit = 10): array
    {
        /** @var list<EmailSyncRun> $runs */
        $runs = $this->entityManager
            ->createQueryBuilder()
            ->select('r')
            ->from(EmailSyncRun::class, 'r')
            ->where('r.emailAccountId = :accountId')
            ->orderBy('r.startedAt', 'DESC')
            ->setParameter('accountId', $emailAccountId)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $runs;
    }

    public function save(EmailSyncRun $run, bool $flush = true): void
    {
        $this->persist($run, $flush);
    }
}
