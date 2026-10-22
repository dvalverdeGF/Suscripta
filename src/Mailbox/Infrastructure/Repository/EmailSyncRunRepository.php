<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Repository;

use App\Mailbox\Domain\Entity\EmailSyncRun;
use App\Mailbox\Domain\Enum\SyncRunStatus;
use App\Mailbox\Domain\Repository\EmailSyncRunRepositoryInterface;
use App\Shared\Domain\ValueObject\DateRange;
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

    public function findRecentForOrganization(Uuid $organizationId, int $limit = 10): array
    {
        /** @var list<EmailSyncRun> $runs */
        $runs = $this->entityManager
            ->createQueryBuilder()
            ->select('r')
            ->from(EmailSyncRun::class, 'r')
            ->where('r.organizationId = :organizationId')
            ->orderBy('r.startedAt', 'DESC')
            ->setParameter('organizationId', $organizationId)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $runs;
    }

    public function statsForOrganization(Uuid $organizationId, DateRange $period): array
    {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select(
                'COUNT(r.id) AS runs',
                'COALESCE(SUM(CASE WHEN r.status = :failed THEN 1 ELSE 0 END), 0) AS failed',
                'COALESCE(SUM(r.messagesSeen), 0) AS seen',
                'COALESCE(SUM(r.messagesProcessed), 0) AS processed',
                'COALESCE(SUM(r.messagesSkipped), 0) AS skipped',
                'COALESCE(SUM(r.messagesBackfilled), 0) AS backfilled',
                'COALESCE(SUM(r.discoveriesCreated), 0) AS discoveries',
                'COALESCE(AVG(SECONDS_BETWEEN(r.startedAt, r.finishedAt)), 0) AS averageDurationMs',
            )
            ->from(EmailSyncRun::class, 'r')
            ->where('r.organizationId = :organizationId')
            ->andWhere('r.startedAt >= :from')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('from', $period->from)
            ->setParameter('failed', SyncRunStatus::FAILED);

        if (null !== $period->to) {
            $qb->andWhere('r.startedAt < :to')->setParameter('to', $period->to);
        }

        /** @var array<string, int|string>|null $row */
        $row = $qb->getQuery()->getSingleResult();

        return [
            'runs' => (int) ($row['runs'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'seen' => (int) ($row['seen'] ?? 0),
            'processed' => (int) ($row['processed'] ?? 0),
            'skipped' => (int) ($row['skipped'] ?? 0),
            'backfilled' => (int) ($row['backfilled'] ?? 0),
            'discoveries' => (int) ($row['discoveries'] ?? 0),
            // `SECONDS_BETWEEN` devuelve segundos; el DTO habla de milisegundos.
            'averageDurationMs' => (int) round((float) ($row['averageDurationMs'] ?? 0) * 1000),
        ];
    }

    public function save(EmailSyncRun $run, bool $flush = true): void
    {
        $this->persist($run, $flush);
    }

    public function removeForAccount(Uuid $emailAccountId): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(EmailSyncRun::class, 'r')
            ->where('r.emailAccountId = :accountId')
            ->setParameter('accountId', $emailAccountId)
            ->getQuery()
            ->execute();
    }
}
