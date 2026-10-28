<?php

declare(strict_types=1);

namespace App\Subscriptions\Application\Dto;

use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Money;

/**
 * Datos de un precio nuevo del catálogo.
 *
 * El importe llega ya como `Money` (unidad mínima + moneda) porque el
 * formulario lo construye sin pasar por coma flotante (D-09).
 */
final readonly class PlanPriceInput
{
    public function __construct(
        public Money $amount,
        public BillingPeriod $billingPeriod,
        public ?string $externalPriceId = null,
    ) {
    }
}
