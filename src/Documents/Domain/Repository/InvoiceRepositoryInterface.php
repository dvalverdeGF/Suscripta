<?php

declare(strict_types=1);

namespace App\Documents\Domain\Repository;

use App\Documents\Domain\Entity\Invoice;
use App\Documents\Domain\Enum\InvoiceStatus;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

interface InvoiceRepositoryInterface
{
    public function find(Uuid $id): ?Invoice;

    /**
     * @return list<Invoice>
     */
    public function findForOrganization(?InvoiceStatus $status = null, int $limit = 100): array;

    /**
     * @return list<Invoice>
     */
    public function findForService(Uuid $serviceId): array;

    /**
     * Facturas emitidas dentro de una ventana. Es lo que permite reconstruir
     * el gasto real a partir de lo que se cobró, no de lo que se esperaba.
     *
     * @return list<Invoice>
     */
    public function findIssuedBetween(DateTimeImmutable $from, DateTimeImmutable $until): array;

    public function findByDedupKey(string $dedupKey): ?Invoice;

    public function countForOrganization(): int;

    public function save(Invoice $invoice, bool $flush = true): void;

    public function remove(Invoice $invoice, bool $flush = true): void;

    public function flush(): void;
}
