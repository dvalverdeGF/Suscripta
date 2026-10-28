<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\Port\PaymentGatewayInterface;
use App\Billing\Domain\Repository\PaymentEventRepositoryInterface;
use App\Billing\Domain\ValueObject\GatewayEvent;
use Psr\Log\LoggerInterface;

use function sprintf;

use Throwable;

/**
 * Aplica un evento de la pasarela **una sola vez** (Fase 24).
 *
 * ## El orden importa
 *
 * 1. **Reservar** el evento. Si ya estaba reservado, se responde «duplicado» y
 *    no se toca nada. La reserva es atómica (ver `PaymentEventRepository`).
 * 2. **Aplicar** el efecto.
 * 3. **Marcar** el resultado: aplicado, o ignorado con el motivo.
 *
 * Si el paso 2 falla, se **suelta** la reserva y se propaga el error para que el
 * controlador responda 500. La pasarela reintentará y, como la reserva ya no
 * está, el reintento se procesa de verdad. Sin soltarla, un fallo de red de dos
 * segundos se convertiría en un evento perdido para siempre.
 *
 * ## Lo que no se entiende se apunta y se olvida
 *
 * Un tipo de evento que no está en `GatewayEvent::HANDLED_TYPES` —Stripe tiene
 * cientos— se marca como ignorado y se responde 200. Devolver un error haría
 * que la pasarela lo reintentara durante días.
 */
final readonly class HandlePaymentEvent
{
    public function __construct(
        private PaymentEventRepositoryInterface $events,
        private PaymentGatewayInterface $gateway,
        private ApplyGatewaySubscription $applySubscription,
        private RecordSalesInvoice $recordInvoice,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(GatewayEvent $event): PaymentEventOutcome
    {
        if (!$this->events->claim($event->id, $event->type, $event->createdAt)) {
            $this->logger->info('Evento de la pasarela repetido: no se aplica dos veces.', [
                'event' => $event->id,
                'type' => $event->type,
            ]);

            return PaymentEventOutcome::DUPLICATE;
        }

        $record = $this->events->find($event->id);

        if (null === $record) {
            // La reserva se acaba de hacer, así que esto solo pasa si alguien
            // borra la fila entre las dos consultas.
            return PaymentEventOutcome::IGNORED;
        }

        try {
            $reason = $this->apply($event);
        } catch (Throwable $exception) {
            $this->events->release($event->id);

            throw $exception;
        }

        if (null === $reason) {
            $record->markApplied();
        } else {
            $record->markIgnored($reason);
        }

        $this->events->save($record);

        return null === $reason ? PaymentEventOutcome::APPLIED : PaymentEventOutcome::IGNORED;
    }

    /**
     * Aplica el evento. Devuelve `null` si se aplicó, o el motivo por el que no.
     */
    private function apply(GatewayEvent $event): ?string
    {
        if (!$event->isHandled()) {
            return sprintf('el tipo «%s» no se aplica', $event->type);
        }

        return match ($event->type) {
            'checkout.session.completed' => $this->onCheckoutCompleted($event),
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->onSubscription($event),
            'invoice.paid' => $this->onInvoice($event, true),
            'invoice.payment_failed' => $this->onInvoice($event, false),
            default => sprintf('el tipo «%s» no se aplica', $event->type),
        };
    }

    /**
     * El cliente ha terminado el pago.
     *
     * La sesión trae el identificador de la suscripción, pero no su periodo:
     * hay que preguntárselo a la pasarela. Es una llamada de red dentro de un
     * webhook, y se acepta a cambio de no inventarse fechas.
     */
    private function onCheckoutCompleted(GatewayEvent $event): ?string
    {
        $session = $event->checkoutSession;

        if (null === $session) {
            return 'la sesión de pago no se ha podido leer';
        }

        if (!$session->isComplete()) {
            return sprintf('la sesión de pago está «%s»', $session->status);
        }

        if (null === $session->subscriptionExternalId) {
            return 'la sesión de pago no tiene suscripción';
        }

        $remote = $this->gateway->findSubscription($session->subscriptionExternalId);

        if (null === $remote) {
            return 'la pasarela no conoce la suscripción de la sesión';
        }

        return null === $this->applySubscription->__invoke($remote)
            ? 'la suscripción no se ha podido aplicar'
            : null;
    }

    private function onSubscription(GatewayEvent $event): ?string
    {
        $remote = $event->subscription;

        if (null === $remote) {
            return 'la suscripción del evento no se ha podido leer';
        }

        return null === $this->applySubscription->__invoke($remote)
            ? 'la suscripción no se ha podido aplicar'
            : null;
    }

    /**
     * Factura cobrada o rechazada.
     *
     * Guardar la factura no basta para dar el servicio: el periodo lo dicta la
     * pasarela, así que después de apuntar el cobro se vuelve a sincronizar la
     * suscripción. Así `invoice.paid` renueva el periodo **con las fechas de
     * quien cobra** y no con las que calcularía Suscripta por su cuenta.
     */
    private function onInvoice(GatewayEvent $event, bool $paid): ?string
    {
        $invoice = $event->invoice;

        if (null === $invoice) {
            return 'la factura del evento no se ha podido leer';
        }

        $stored = $this->recordInvoice->__invoke($invoice);

        if (null === $stored) {
            return 'la factura no tiene organización identificable';
        }

        if (null === $invoice->subscriptionExternalId) {
            return null;
        }

        $remote = $this->gateway->findSubscription($invoice->subscriptionExternalId);

        if (null === $remote) {
            // La factura queda guardada aunque la suscripción ya no exista:
            // el cobro ocurrió y tiene que seguir en el historial.
            return null;
        }

        $this->applySubscription->__invoke($remote);

        $this->logger->info($paid ? 'Cobro recibido.' : 'Cobro rechazado.', [
            'invoice' => $invoice->externalId,
            'subscription' => $invoice->subscriptionExternalId,
        ]);

        return null;
    }
}
