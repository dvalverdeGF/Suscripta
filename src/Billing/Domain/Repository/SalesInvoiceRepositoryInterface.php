<?php

declare(strict_types=1);

namespace App\Billing\Domain\Repository;

use App\Billing\Domain\Entity\SalesInvoice;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

interface SalesInvoiceRepositoryInterface
{
    /**
     * Busca por el identificador de la pasarela, que es el que llega en el
     * evento. Es la única consulta de esta interfaz que **no** se limita al
     * inquilino actual: la usa el webhook, que llega sin sesión y tiene que
     * encontrar la factura antes de saber de qué organización es.
     */
    public function findByExternalId(string $externalInvoiceId): ?SalesInvoice;

    /**
     * Las facturas de la organización activa, de la más reciente a la más
     * antigua.
     *
     * @return list<SalesInvoice>
     */
    public function findForOrganization(int $limit = 60): array;

    /**
     * @return list<SalesInvoice>
     */
    public function findForSubscription(Uuid $subscriptionId): array;

    public function save(SalesInvoice $invoice): void;

    /**
     * Suma de lo cobrado en la organización activa desde una fecha.
     *
     * Suma en la base de datos y no en PHP: una organización con dos años de
     * facturas no necesita cargarlas todas para pintar un total.
     */
    public function totalPaidSince(DateTimeImmutable $since): int;
}
