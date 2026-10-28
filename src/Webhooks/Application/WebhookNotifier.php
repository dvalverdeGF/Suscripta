<?php

declare(strict_types=1);

namespace App\Webhooks\Application;

use App\Shared\Application\Clock;
use App\Webhooks\Application\Message\DeliverWebhookMessage;
use App\Webhooks\Domain\Entity\WebhookDelivery;
use App\Webhooks\Domain\Enum\WebhookEvent;
use App\Webhooks\Domain\Repository\WebhookDeliveryRepositoryInterface;
use App\Webhooks\Domain\Repository\WebhookRepositoryInterface;

use const DATE_ATOM;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Anuncia un evento de negocio a los suscriptores de una organización
 * (Fase 20).
 *
 * ## Por qué se llama a mano
 *
 * Suscripta no tiene bus de eventos de dominio: los casos de uso hablan con sus
 * repositorios y ya está. Un bus habría estado bien, pero introducirlo ahora
 * para cuatro eventos obligaría a reescribir los casos de uso existentes. En su
 * lugar, cada caso de uso que importa llama a `notify()` al final, con los datos
 * que ya tiene en la mano. Es explícito y se ve en el diff; el precio es que
 * hay que acordarse, y por eso cada llamada tiene su test.
 *
 * ## Por qué no rompe nada
 *
 * `notify()` nunca lanza. Un webhook es una notificación, no una transacción:
 * si falla al crear la fila de entrega, el servicio se creó igual y el usuario
 * no debe ver un error por un integrador mal configurado. Los fallos se
 * registran en el log.
 *
 * ## Qué se guarda y qué se encola
 *
 * Se guarda **una entrega por suscriptor** y se encola un mensaje por entrega.
 * El mensaje solo lleva el identificador; el cuerpo queda en base de datos para
 * que un reintento mande exactamente lo mismo.
 */
final readonly class WebhookNotifier
{
    public function __construct(
        private WebhookRepositoryInterface $webhooks,
        private WebhookDeliveryRepositoryInterface $deliveries,
        private MessageBusInterface $messageBus,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return int número de entregas programadas
     */
    public function notify(Uuid $organizationId, WebhookEvent $event, array $data): int
    {
        try {
            $subscribers = $this->webhooks->findActiveSubscribers($organizationId, $event);
        } catch (Throwable $exception) {
            $this->logger->error('No se han podido leer los suscriptores de webhooks.', [
                'event' => $event->value,
                'error' => $exception->getMessage(),
            ]);

            return 0;
        }

        $scheduled = 0;

        foreach ($subscribers as $webhook) {
            $deliveryId = Uuid::v7();

            try {
                $delivery = new WebhookDelivery(
                    organizationId: $organizationId,
                    webhookId: $webhook->getId(),
                    event: $event,
                    payload: $this->envelope($deliveryId, $event, $data),
                    idempotencyKey: (string) $deliveryId,
                    now: $this->clock->now(),
                );

                $this->deliveries->save($delivery);
                $this->messageBus->dispatch(
                    new DeliverWebhookMessage($delivery->getId()),
                    [new TransportNamesStamp(['webhooks'])],
                );
                ++$scheduled;
            } catch (Throwable $exception) {
                $this->logger->error('No se ha podido programar una entrega de webhook.', [
                    'event' => $event->value,
                    'webhookId' => (string) $webhook->getId(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $scheduled;
    }

    /**
     * Sobre común a todos los eventos.
     *
     * `id` es el de la entrega, no el del evento: cada suscriptor recibe su
     * propia entrega y su propio identificador. Es el mismo valor que viaja en
     * `Idempotency-Key`, así que el receptor puede deduplicar por cualquiera de
     * los dos.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function envelope(Uuid $deliveryId, WebhookEvent $event, array $data): array
    {
        return [
            'id' => (string) $deliveryId,
            'event' => $event->value,
            'occurredAt' => $this->clock->now()->format(DATE_ATOM),
            'data' => $data,
        ];
    }
}
