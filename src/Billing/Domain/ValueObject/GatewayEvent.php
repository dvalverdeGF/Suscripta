<?php

declare(strict_types=1);

namespace App\Billing\Domain\ValueObject;

use DateTimeImmutable;

use function in_array;

/**
 * Un evento recibido de la pasarela, ya verificado y traducido (Fase 24).
 *
 * ## Por qué el evento trae objetos y no un `array`
 *
 * El cuerpo de un webhook de Stripe es un JSON con una forma distinta por tipo
 * de evento, y esa forma cambia con la versión de la API. Si el array crudo
 * llegara a la capa de aplicación, cada `$data['object']['items']['data'][0]`
 * sería un conocimiento de Stripe escondido en el sitio equivocado. Aquí el
 * evento trae **lo que la pasarela ha sabido leer**, y lo que no ha sabido leer
 * llega como `null`.
 *
 * ## `id` es la clave de idempotencia
 *
 * La pasarela reintenta hasta que recibe un 200, así que el mismo evento puede
 * llegar dos, tres o diez veces. `id` es lo que permite aplicar el efecto una
 * sola vez (ver `PaymentEvent`).
 */
final readonly class GatewayEvent
{
    /**
     * Los tipos de evento que esta aplicación sabe aplicar.
     *
     * Un evento que no está aquí **no es un error**: se responde 200 y se
     * apunta, porque devolver un error haría que la pasarela lo reintentara
     * para siempre. Se ignoran los que no se entienden y se procesan los que sí.
     */
    public const array HANDLED_TYPES = [
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.paid',
        'invoice.payment_failed',
    ];

    public function __construct(
        public string $id,
        public string $type,
        public DateTimeImmutable $createdAt,
        public ?GatewaySubscription $subscription = null,
        public ?GatewayCheckoutSession $checkoutSession = null,
        public ?GatewayInvoice $invoice = null,
    ) {
    }

    /**
     * ¿Es un evento que esta aplicación sabe aplicar?
     *
     * Uno que no lo sea no es un error: se responde 200 y se apunta, porque
     * devolver un error haría que la pasarela lo reintentara para siempre.
     */
    public function isHandled(): bool
    {
        return in_array($this->type, self::HANDLED_TYPES, true);
    }
}
