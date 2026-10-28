<?php

declare(strict_types=1);

namespace App\Billing\Domain\ValueObject;

use App\Billing\Domain\Enum\GatewaySubscriptionStatus;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Una suscripción tal y como la ve la pasarela (Fase 24).
 *
 * ## El periodo viene de fuera
 *
 * `currentPeriodStart`/`End` los dicta quien cobra. Si Suscripta los calculara
 * por su cuenta a partir del estado local, un cambio de periodo hecho en el
 * panel de Stripe (un prorrateo, un aplazamiento) dejaría las dos versiones
 * discrepando, y la que manda es la del cobro.
 *
 * ## La organización viaja en los metadatos
 *
 * La pasarela no sabe de organizaciones, así que el identificador se copia a
 * los metadatos al abrir el checkout y vuelve en cada evento. Es lo que permite
 * que un `customer.subscription.updated` que llega suelto —sin sesión, sin
 * usuario— sepa a quién pertenece. Sin ese dato el evento no se aplica: es
 * preferible una suscripción sin actualizar que una actualizada en la
 * organización equivocada.
 */
final readonly class GatewaySubscription
{
    public function __construct(
        public string $externalId,
        public GatewaySubscriptionStatus $status,
        public DateTimeImmutable $currentPeriodStart,
        public DateTimeImmutable $currentPeriodEnd,
        public bool $cancelAtPeriodEnd = false,
        public ?DateTimeImmutable $canceledAt = null,
        public ?Uuid $organizationId = null,
        public ?Uuid $planId = null,
        public ?string $planName = null,
        public ?BillingPeriod $billingPeriod = null,
        public ?Currency $currency = null,
    ) {
    }
}
