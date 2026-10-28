<?php

declare(strict_types=1);

namespace App\Billing\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Money;

use function in_array;
use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * Lo que hace falta para abrir una sesión de pago (Fase 24).
 *
 * ## Los datos del plan viajan con la petición, no se releen
 *
 * El importe y el nombre del plan se copian aquí en el momento de abrir el
 * checkout. Si la pasarela leyera el catálogo por su cuenta, un cambio de
 * precio entre el clic y el cobro cobraría otra cosa que la que el cliente vio.
 *
 * ## `externalPriceId` es opcional a propósito
 *
 * Si el operador ha creado el precio en Stripe y lo ha enlazado en el
 * backoffice, se cobra con ese `price_…`, que es lo correcto cuando hay
 * impuestos, cupones o prorrateos configurados allí. Si no lo ha enlazado, el
 * checkout se abre igual con un precio al vuelo (`price_data`): obligar a
 * duplicar cada precio en dos paneles antes de poder cobrar es la forma más
 * rápida de que la web y Stripe digan cosas distintas.
 */
final readonly class CheckoutRequest
{
    public function __construct(
        public Uuid $organizationId,
        public Uuid $planId,
        public string $planName,
        public Money $amount,
        public BillingPeriod $billingPeriod,
        public int $billingIntervalCount,
        public ?string $externalPriceId,
        public ?string $customerEmail,
        public string $successUrl,
        public string $cancelUrl,
    ) {
        if (!in_array($billingPeriod, BillingPeriod::cases(), true) || !$billingPeriod->isRecurring()) {
            throw new InvalidArgumentException(sprintf('«%s» no se puede cobrar como suscripción.', $billingPeriod->label()));
        }

        if ($amount->amountMinor <= 0) {
            throw new InvalidArgumentException('Un checkout no puede cobrar un importe de cero: eso es el plan gratuito.');
        }

        if ($billingIntervalCount < 1) {
            throw new InvalidArgumentException('El intervalo de cobro tiene que ser de al menos uno.');
        }
    }
}
