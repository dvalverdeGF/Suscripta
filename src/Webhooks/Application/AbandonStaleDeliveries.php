<?php

declare(strict_types=1);

namespace App\Webhooks\Application;

use App\Shared\Application\Clock;
use App\Webhooks\Domain\Repository\WebhookDeliveryRepositoryInterface;
use App\Webhooks\Domain\Service\RetryPolicy;

/**
 * Cierra las entregas que ya no se van a reintentar (Fase 20).
 *
 * Hace falta un barrido aparte porque una entrega puede quedarse pendiente sin
 * que nadie la mire: si el proceso se cayó a mitad de un reintento, la fila
 * sigue `PENDING` con una fecha en el pasado y ningún mensaje en la cola que la
 * reactive. El barrido la abandona en vez de dejarla mintiendo para siempre en
 * el panel.
 */
final readonly class AbandonStaleDeliveries
{
    public function __construct(
        private WebhookDeliveryRepositoryInterface $deliveries,
        private RetryPolicy $retryPolicy,
        private Clock $clock,
    ) {
    }

    /**
     * @return int entregas abandonadas
     */
    public function __invoke(): int
    {
        $now = $this->clock->now();
        $count = 0;

        foreach ($this->deliveries->findAbandonable($this->retryPolicy->abandonAfterDays()) as $delivery) {
            $delivery->abandon('Sin éxito tras el plazo de reintento.', $now);
            $this->deliveries->save($delivery, false);
            ++$count;
        }

        return $count;
    }
}
