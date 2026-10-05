<?php

declare(strict_types=1);

namespace App\Documents\Infrastructure\Repository;

use App\Documents\Domain\Entity\Invoice;
use App\Documents\Domain\Enum\InvoiceStatus;
use App\Documents\Domain\Repository\InvoiceRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;

use function count;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function explode;
use function is_numeric;
use function preg_match;

use Symfony\Component\Uid\Uuid;

final class InvoiceRepository extends DoctrineRepository implements InvoiceRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?Invoice
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForOrganization(?InvoiceStatus $status = null, int $limit = 100): array
    {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->orderBy('i.issuedAt', 'DESC')
            ->setMaxResults($limit);

        if (null !== $status) {
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }

        /** @var list<Invoice> $invoices */
        $invoices = $qb->getQuery()->getResult();

        return $invoices;
    }

    public function findForService(Uuid $serviceId): array
    {
        /** @var list<Invoice> $invoices */
        $invoices = $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.serviceId = :service')
            ->orderBy('i.issuedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $invoices;
    }

    public function findIssuedBetween(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        /** @var list<Invoice> $invoices */
        $invoices = $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.issuedAt >= :from')
            ->andWhere('i.issuedAt < :until')
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->orderBy('i.issuedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $invoices;
    }

    /**
     * La clave de deduplicación se compone de columnas, así que no se puede
     * indexar tal cual. Se filtra primero por las partes que sí están en la
     * tabla (importe, moneda y día de emisión) y se compara la clave completa
     * sobre ese conjunto, que en la práctica son una o dos filas.
     */
    public function findByDedupKey(string $dedupKey): ?Invoice
    {
        $parts = explode('|', $dedupKey);

        if (5 !== count($parts)) {
            return null;
        }

        [, , $amountMinor, $currency, $issuedOn] = $parts;

        if (!is_numeric($amountMinor) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issuedOn)) {
            return null;
        }

        $day = new DateTimeImmutable($issuedOn);

        /** @var list<Invoice> $candidates */
        $candidates = $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.totalAmountMinor = :amount')
            ->andWhere('i.currency = :currency')
            ->andWhere('i.issuedAt >= :from')
            ->andWhere('i.issuedAt < :until')
            ->setParameter('amount', (int) $amountMinor)
            ->setParameter('currency', $currency)
            ->setParameter('from', $day)
            ->setParameter('until', $day->modify('+1 day'))
            ->getQuery()
            ->getResult();

        foreach ($candidates as $invoice) {
            if ($invoice->buildDedupKey() === $dedupKey) {
                return $invoice;
            }
        }

        return null;
    }

    public function countForOrganization(): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(Invoice::class, 'i')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(Invoice $invoice, bool $flush = true): void
    {
        $this->persist($invoice, $flush);
    }

    public function remove(Invoice $invoice, bool $flush = true): void
    {
        $this->delete($invoice, $flush);
    }

    public function flush(): void
    {
        parent::flush();
    }
}
