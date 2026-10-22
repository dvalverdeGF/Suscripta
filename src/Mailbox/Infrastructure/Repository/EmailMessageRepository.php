<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Repository;

use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Shared\Domain\ValueObject\DateRange;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use DateTimeImmutable;
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

    /**
     * Mensajes aplazados esperando presupuesto de IA (Fase 14).
     */
    public function findDeferred(int $limit = 200): array
    {
        /** @var list<EmailMessage> $messages */
        $messages = $this->entityManager
            ->createQueryBuilder()
            ->select('m')
            ->from(EmailMessage::class, 'm')
            ->where('m.processingState = :state')
            ->andWhere('m.receivedAt IS NOT NULL')
            ->setParameter('state', MessageProcessingState::DEFERRED)
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

    /**
     * Cómo se han resuelto los mensajes de la organización en un periodo.
     *
     * @return array{total: int, byTier: array<string, int>, averageDurationMs: int}
     */
    public function extractionStats(Uuid $organizationId, DateRange $period): array
    {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('m.extractionTier AS tier', 'COUNT(m.id) AS total')
            ->from(EmailMessage::class, 'm')
            ->where('m.organizationId = :organizationId')
            ->andWhere('m.receivedAt >= :from')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('from', $period->from)
            ->groupBy('m.extractionTier');

        if (null !== $period->to) {
            $qb->andWhere('m.receivedAt < :to')->setParameter('to', $period->to);
        }

        /** @var list<array{tier: string, total: int|string}> $rows */
        $rows = $qb->getQuery()->getResult();

        $byTier = [];
        $total = 0;

        foreach ($rows as $row) {
            $tier = $row['tier'];

            if (null === $tier) {
                continue;
            }

            $count = (int) $row['total'];

            // La columna está mapeada con `enumType`, así que Doctrine
            // devuelve el objeto enum y no su valor. Indexar el array por el
            // objeto es imposible (un enum no puede ser clave de array) y la
            // vista busca las claves por valor (`deterministic`, `ai_cheap`).
            $byTier[$tier instanceof ExtractionTier ? $tier->value : (string) $tier] = $count;
            $total += $count;
        }

        return [
            'total' => $total,
            'byTier' => $byTier,
            'averageDurationMs' => $this->averageProcessingMs($organizationId, $period),
        ];
    }

    /**
     * Tiempo medio que tarda un correo en procesarse, de la recepción al
     * finish.
     *
     * Se mide en SQL con la función `SECONDS_BETWEEN`, que devuelve segundos
     * en cualquier motor. Los mensajes sin `processedAt` (los que aún no se
     * han tocado) quedan fuera: medirlos sería medir el tiempo que el
     * usuario ha tardado en conectar el buzón.
     */
    private function averageProcessingMs(Uuid $organizationId, DateRange $period): int
    {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('COALESCE(AVG(SECONDS_BETWEEN(m.receivedAt, m.processedAt)), 0)')
            ->from(EmailMessage::class, 'm')
            ->where('m.organizationId = :organizationId')
            ->andWhere('m.receivedAt >= :from')
            ->andWhere('m.receivedAt IS NOT NULL')
            ->andWhere('m.processedAt IS NOT NULL')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('from', $period->from);

        if (null !== $period->to) {
            $qb->andWhere('m.receivedAt < :to')->setParameter('to', $period->to);
        }

        $seconds = $qb->getQuery()->getSingleScalarResult();

        return (int) round((float) $seconds * 1000);
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

    public function countByAccount(): array
    {
        /** @var list<array{accountId: Uuid, total: int|string}> $rows */
        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select('m.emailAccountId AS accountId', 'COUNT(m.id) AS total')
            ->from(EmailMessage::class, 'm')
            ->groupBy('m.emailAccountId')
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['accountId']->toRfc4122()] = (int) $row['total'];
        }

        return $counts;
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

    public function removeForOrganization(Uuid $organizationId): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(EmailMessage::class, 'm')
            ->where('m.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->getQuery()
            ->execute();
    }

    public function purgeOlderThan(DateTimeImmutable $threshold, int $limit = 500): int
    {
        $ids = $this->scalarIds($this->entityManager
            ->createQueryBuilder()
            ->select('m.id')
            ->from(EmailMessage::class, 'm')
            ->where('m.createdAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult());

        if ([] === $ids) {
            return 0;
        }

        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(EmailMessage::class, 'm')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}
