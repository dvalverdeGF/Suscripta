<?php

declare(strict_types=1);

namespace App\Documents\Infrastructure\Repository;

use App\Documents\Domain\Entity\Document;
use App\Documents\Domain\Enum\DocumentType;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DocumentRepository extends DoctrineRepository implements DocumentRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?Document
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(Document::class, 'd')
            ->where('d.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForOrganization(?DocumentType $type = null, int $limit = 100): array
    {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(Document::class, 'd')
            ->where('d.deletedAt IS NULL')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit);

        if (null !== $type) {
            $qb->andWhere('d.type = :type')->setParameter('type', $type);
        }

        /** @var list<Document> $documents */
        $documents = $qb->getQuery()->getResult();

        return $documents;
    }

    public function findForService(Uuid $serviceId): array
    {
        /** @var list<Document> $documents */
        $documents = $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(Document::class, 'd')
            ->where('d.serviceId = :service')
            ->andWhere('d.deletedAt IS NULL')
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $documents;
    }

    public function findForInvoice(Uuid $invoiceId): array
    {
        /** @var list<Document> $documents */
        $documents = $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(Document::class, 'd')
            ->where('d.invoiceId = :invoice')
            ->andWhere('d.deletedAt IS NULL')
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $documents;
    }

    public function findForEmailMessage(Uuid $emailMessageId): array
    {
        /** @var list<Document> $documents */
        $documents = $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(Document::class, 'd')
            ->where('d.emailMessageId = :message')
            ->andWhere('d.deletedAt IS NULL')
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $documents;
    }

    public function findByChecksum(string $checksumSha256): ?Document
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(Document::class, 'd')
            ->where('d.checksumSha256 = :checksum')
            ->setParameter('checksum', $checksumSha256)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countForOrganization(): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(Document::class, 'd')
            ->where('d.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByType(): array
    {
        /** @var list<array{type: DocumentType, total: int|string}> $rows */
        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select('d.type AS type', 'COUNT(d.id) AS total')
            ->from(Document::class, 'd')
            ->where('d.deletedAt IS NULL')
            ->groupBy('d.type')
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['type']->value] = (int) $row['total'];
        }

        return $counts;
    }

    public function save(Document $document, bool $flush = true): void
    {
        $this->persist($document, $flush);
    }

    public function remove(Document $document, bool $flush = true): void
    {
        $this->delete($document, $flush);
    }

    public function flush(): void
    {
        parent::flush();
    }
}
