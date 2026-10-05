<?php

declare(strict_types=1);

namespace App\Tests\Support\Documents;

use App\Documents\Domain\Entity\Invoice;
use App\Documents\Domain\Enum\InvoiceStatus;
use App\Documents\Domain\Repository\InvoiceRepositoryInterface;

use function array_values;
use function count;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Repositorio de facturas en memoria, con la misma deduplicación por clave que
 * el de verdad. Sin memoria no se puede demostrar que registrar dos veces el
 * mismo cobro no lo duplica.
 */
final class InMemoryInvoiceRepository implements InvoiceRepositoryInterface
{
    /** @var array<string, Invoice> clave = identificador en formato RFC 4122 */
    private array $invoices = [];

    public int $flushCount = 0;

    public function find(Uuid $id): ?Invoice
    {
        return $this->invoices[$id->toRfc4122()] ?? null;
    }

    public function findForOrganization(?InvoiceStatus $status = null, int $limit = 100): array
    {
        $result = [];

        foreach ($this->invoices as $invoice) {
            if (null !== $status && $invoice->getStatus() !== $status) {
                continue;
            }

            $result[] = $invoice;

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    public function findForService(Uuid $serviceId): array
    {
        $result = [];

        foreach ($this->invoices as $invoice) {
            if ($invoice->getServiceId()?->toRfc4122() === $serviceId->toRfc4122()) {
                $result[] = $invoice;
            }
        }

        return $result;
    }

    public function findIssuedBetween(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $result = [];

        foreach ($this->invoices as $invoice) {
            $issuedAt = $invoice->getIssuedAt();

            if ($issuedAt >= $from && $issuedAt <= $until) {
                $result[] = $invoice;
            }
        }

        return $result;
    }

    public function findByDedupKey(string $dedupKey): ?Invoice
    {
        foreach ($this->invoices as $invoice) {
            if ($invoice->buildDedupKey() === $dedupKey) {
                return $invoice;
            }
        }

        return null;
    }

    public function countForOrganization(): int
    {
        return count($this->invoices);
    }

    public function save(Invoice $invoice, bool $flush = true): void
    {
        $this->invoices[$invoice->getId()->toRfc4122()] = $invoice;
    }

    public function remove(Invoice $invoice, bool $flush = true): void
    {
        unset($this->invoices[$invoice->getId()->toRfc4122()]);
    }

    public function flush(): void
    {
        ++$this->flushCount;
    }

    /**
     * @return list<Invoice>
     */
    public function all(): array
    {
        return array_values($this->invoices);
    }
}
