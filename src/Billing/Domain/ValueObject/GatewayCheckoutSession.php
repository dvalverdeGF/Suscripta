<?php

declare(strict_types=1);

namespace App\Billing\Domain\ValueObject;

use App\Shared\Domain\ValueObject\BillingPeriod;
use Symfony\Component\Uid\Uuid;

/**
 * Una sesión de pago consultada a la pasarela (Fase 24).
 *
 * Se consulta al volver del checkout para no depender de que el webhook haya
 * llegado ya: el cliente que acaba de pagar tiene que ver su plan activo
 * **ahora**, no dentro de los diez segundos que tarda el evento. El webhook
 * sigue siendo la fuente de verdad para todo lo que pasa sin navegador delante.
 */
final readonly class GatewayCheckoutSession
{
    public function __construct(
        public string $externalId,
        /**
         * `complete`, `open` o `expired` en Stripe. Solo `complete` significa
         * que el cliente terminó y pagó.
         */
        public string $status,
        public ?string $subscriptionExternalId = null,
        public ?Uuid $organizationId = null,
        public ?Uuid $planId = null,
        public ?string $planName = null,
        public ?BillingPeriod $billingPeriod = null,
    ) {
    }

    public function isComplete(): bool
    {
        return 'complete' === $this->status;
    }
}
