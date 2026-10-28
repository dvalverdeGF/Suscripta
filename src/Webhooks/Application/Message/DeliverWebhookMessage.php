<?php

declare(strict_types=1);

namespace App\Webhooks\Application\Message;

use Symfony\Component\Uid\Uuid;

/**
 * «Intenta entregar esta fila de `webhook_delivery`».
 *
 * Lleva solo el identificador: el cuerpo, el estado y los intentos viven en
 * base de datos, que es la única fuente de verdad. Si el mensaje llevara el
 * cuerpo y se reintentara desde la cola, una entrega ya entregada volvería a
 * salir.
 */
final readonly class DeliverWebhookMessage
{
    public function __construct(public Uuid $deliveryId)
    {
    }
}
