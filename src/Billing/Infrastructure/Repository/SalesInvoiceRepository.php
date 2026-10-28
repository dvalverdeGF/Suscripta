<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Repository;

use App\Billing\Domain\Entity\SalesInvoice;
use App\Billing\Domain\Enum\InvoiceStatus;
use App\Billing\Domain\Repository\SalesInvoiceRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Facturas de venta.
 *
 * `SalesInvoice` implementa `TenantAwareInterface`, así que el filtro `tenant`
 * de Doctrine añade el `organization_id` a las consultas de esta clase sin que
 * el repositorio tenga que acordarse (D-11). La única excepción es
 * `findByExternalId`, que la usa el webhook antes de saber de qué organización
 * es la factura; el filtro no restringe cuando no hay organización en contexto,
 * y el webhook no la tiene.
 */
final class SalesInvoiceRepository extends DoctrineRepository implements SalesInvoiceRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function findByExternalId(string $externalInvoiceId): ?SalesInvoice
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(SalesInvoice::class, 'i')
            ->where('i.externalInvoiceId = :externalInvoiceId')
            ->setParameter('externalInvoiceId', $externalInvoiceId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForOrganization(int $limit = 60): array
    {
        /** @var list<SalesInvoice> $invoices */
        $invoices = $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(SalesInvoice::class, 'i')
            ->orderBy('i.issuedAt', 'DESC')
            ->addOrderBy('i.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $invoices;
    }

    public function findForSubscription(Uuid $subscriptionId): array
    {
        /** @var list<SalesInvoice> $invoices */
        $invoices = $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(SalesInvoice::class, 'i')
            ->where('i.subscriptionId = :subscriptionId')
            ->setParameter('subscriptionId', $subscriptionId)
            ->orderBy('i.issuedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $invoices;
    }

    public function save(SalesInvoice $invoice): void
    {
        $this->persist($invoice, true);
    }

    public function totalPaidSince(DateTimeImmutable $since): int
    {
        $total = $this->entityManager
            ->createQueryBuilder()
            ->select('SUM(i.amountMinor)')
            ->from(SalesInvoice::class, 'i')
            ->where('i.status = :paid')
            ->andWhere('i.issuedAt >= :since')
            ->setParameter('paid', InvoiceStatus::PAID)
            ->setParameter('since', $since, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($total ?? 0);
    }
}
