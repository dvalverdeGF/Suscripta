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

    /**
     * Fecha del último cobro conocido de cada servicio.
     *
     * Lo que hace falta en el barrido de cambios no es el historial de
     * facturas, sino **cuándo se cobró por última vez**: con esa fecha se
     * responde a las dos preguntas del barrido —«¿ha llegado el cobro que
     * esperaba?» y «¿cuándo fue la última señal de vida de este servicio?»—
     * sin cargar el historial completo en memoria.
     *
     * @return array<string, DateTimeImmutable> serviceId (RFC 4122) => fecha del último cobro
     */
    public function findLastIssuedAtByService(): array;

    public function findByDedupKey(string $dedupKey): ?Invoice;

    public function countForOrganization(): int;

    public function save(Invoice $invoice, bool $flush = true): void;

    public function remove(Invoice $invoice, bool $flush = true): void;

    public function flush(): void;
}
