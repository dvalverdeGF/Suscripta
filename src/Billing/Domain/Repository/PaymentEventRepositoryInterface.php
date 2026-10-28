<?php

declare(strict_types=1);

namespace App\Billing\Domain\Repository;

use App\Billing\Domain\Entity\PaymentEvent;
use DateTimeImmutable;

interface PaymentEventRepositoryInterface
{
    /**
     * Reserva el evento para este proceso.
     *
     * Devuelve `true` solo al primero que lo intenta; el resto recibe `false`
     * porque la clave ya existe. La reserva es un `INSERT` a pelo contra la
     * tabla y **no** un `SELECT` seguido de un `INSERT`: entre la lectura y la
     * escritura cabe el duplicado que llega en paralelo, que es justo el que hay
     * que parar.
     */
    public function claim(string $externalEventId, string $type, DateTimeImmutable $occurredAt): bool;

    /**
     * Suelta una reserva cuyo efecto no se pudo aplicar.
     *
     * Se llama cuando el procesamiento falla y la respuesta es un error: la
     * pasarela reintentará, y para entonces la reserva no puede seguir ahí o el
     * reintento se tomaría por duplicado y el evento se perdería.
     */
    public function release(string $externalEventId): void;

    public function find(string $externalEventId): ?PaymentEvent;

    public function save(PaymentEvent $event): void;
}
