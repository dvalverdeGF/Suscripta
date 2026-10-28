<?php

declare(strict_types=1);

namespace App\Webhooks\Application\Message;

use App\Webhooks\Application\DeliverWebhook;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Encola el intento de entrega.
 *
 * Delgado a propósito: la política de reintentos vive en `DeliverWebhook` y no
 * en Messenger, porque el estado tiene que quedar en la fila de la entrega (el
 * panel lo muestra) y no solo en la cola. Si el handler lanzara, Messenger
 * reintentaría por su cuenta y habría dos contadores de intentos.
 */
#[AsMessageHandler]
final readonly class DeliverWebhookHandler
{
    public function __construct(private DeliverWebhook $deliverWebhook)
    {
    }

    public function __invoke(DeliverWebhookMessage $message): void
    {
        ($this->deliverWebhook)($message->deliveryId);
    }
}
