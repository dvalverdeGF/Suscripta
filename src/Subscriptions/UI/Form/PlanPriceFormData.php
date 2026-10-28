<?php

declare(strict_types=1);

namespace App\Subscriptions\UI\Form;

use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use App\Subscriptions\Application\Dto\PlanPriceInput;

/**
 * Datos del formulario de precio.
 *
 * El importe llega como `Money` (el `MoneyType` del proyecto lo construye a
 * partir de un texto decimal, sin pasar por `float`) y la moneda se aplica
 * encima: el campo de importe se reconstruye en cada envío con la moneda
 * elegida, porque el número de decimales depende de ella (el yen no tiene).
 */
final class PlanPriceFormData
{
    public ?Money $amount = null;
    public ?Currency $currency = Currency::EUR;
    public ?BillingPeriod $billingPeriod = BillingPeriod::MONTHLY;
    public ?string $externalPriceId = null;

    public function toInput(): PlanPriceInput
    {
        $amount = $this->amount ?? Money::zero($this->currency ?? Currency::EUR);

        return new PlanPriceInput(
            amount: Money::of($amount->amountMinor, $this->currency ?? $amount->currency),
            billingPeriod: $this->billingPeriod ?? BillingPeriod::MONTHLY,
            externalPriceId: $this->externalPriceId,
        );
    }
}
