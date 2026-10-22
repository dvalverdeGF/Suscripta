<?php

declare(strict_types=1);

namespace App\Processing\Infrastructure\Repository;

use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function mb_strtolower;

use Symfony\Component\Uid\Uuid;

use function trim;

final class MailboxKnowledgeRepository extends DoctrineRepository implements MailboxKnowledgeRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?MailboxKnowledgeEntry
    {
        return $this->entityManager->find(MailboxKnowledgeEntry::class, $id);
    }

    public function findForAccount(Uuid $emailAccountId): array
    {
        /** @var list<MailboxKnowledgeEntry> $entries */
        $entries = $this->entityManager
            ->createQueryBuilder()
            ->select('e')
            ->from(MailboxKnowledgeEntry::class, 'e')
            ->where('e.emailAccountId = :accountId')
            ->setParameter('accountId', $emailAccountId)
            ->orderBy('e.type', 'ASC')
            ->addOrderBy('e.confidence', 'DESC')
            ->addOrderBy('e.value', 'ASC')
            ->getQuery()
            ->getResult();

        return $entries;
    }

    public function findByType(Uuid $emailAccountId, MailboxKnowledgeType $type): array
    {
        /** @var list<MailboxKnowledgeEntry> $entries */
        $entries = $this->entityManager
            ->createQueryBuilder()
            ->select('e')
            ->from(MailboxKnowledgeEntry::class, 'e')
            ->where('e.emailAccountId = :accountId')
            ->andWhere('e.type = :type')
            ->setParameter('accountId', $emailAccountId)
            ->setParameter('type', $type)
            ->orderBy('e.confidence', 'DESC')
            ->addOrderBy('e.value', 'ASC')
            ->getQuery()
            ->getResult();

        return $entries;
    }

    public function findByValue(Uuid $emailAccountId, MailboxKnowledgeType $type, string $value): ?MailboxKnowledgeEntry
    {
        $value = $type->isPattern() ? trim($value) : mb_strtolower(trim($value));

        return $this->entityManager
            ->createQueryBuilder()
            ->select('e')
            ->from(MailboxKnowledgeEntry::class, 'e')
            ->where('e.emailAccountId = :accountId')
            ->andWhere('e.type = :type')
            ->andWhere('e.value = :value')
            ->setParameter('accountId', $emailAccountId)
            ->setParameter('type', $type)
            ->setParameter('value', $value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findStale(DateTimeImmutable $olderThan, int $maxHitCount = 0): array
    {
        /** @var list<MailboxKnowledgeEntry> $entries */
        $entries = $this->entityManager
            ->createQueryBuilder()
            ->select('e')
            ->from(MailboxKnowledgeEntry::class, 'e')
            ->where('e.hitCount <= :maxHitCount')
            ->andWhere('e.updatedAt < :olderThan')
            ->setParameter('maxHitCount', $maxHitCount)
            ->setParameter('olderThan', $olderThan)
            ->orderBy('e.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $entries;
    }

    public function save(MailboxKnowledgeEntry $entry, bool $flush = true): void
    {
        $this->persist($entry, $flush);
    }

    public function saveAll(iterable $entries): void
    {
        foreach ($entries as $entry) {
            $this->persist($entry, false);
        }

        $this->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function remove(MailboxKnowledgeEntry $entry, bool $flush = true): void
    {
        $this->delete($entry, $flush);
    }

    public function removeForAccount(Uuid $emailAccountId): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(MailboxKnowledgeEntry::class, 'k')
            ->where('k.emailAccountId = :accountId')
            ->setParameter('accountId', $emailAccountId)
            ->getQuery()
            ->execute();
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(MailboxKnowledgeEntry::class, 'k')
            ->where('k.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->getQuery()
            ->execute();
    }

    public function pruneUnusedBefore(DateTimeImmutable $olderThan, int $limit = 500): int
    {
        $ids = $this->scalarIds($this->entityManager
            ->createQueryBuilder()
            ->select('k.id')
            ->from(MailboxKnowledgeEntry::class, 'k')
            ->where('k.lastUsedAt IS NULL OR k.lastUsedAt < :olderThan')
            ->setParameter('olderThan', $olderThan)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult());

        if ([] === $ids) {
            return 0;
        }

        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(MailboxKnowledgeEntry::class, 'k')
            ->where('k.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    public function findForOrganization(Uuid $organizationId): array
    {
        /** @var list<MailboxKnowledgeEntry> $entries */
        $entries = $this->entityManager
            ->createQueryBuilder()
            ->select('k')
            ->from(MailboxKnowledgeEntry::class, 'k')
            ->where('k.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('k.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $entries;
    }
}
