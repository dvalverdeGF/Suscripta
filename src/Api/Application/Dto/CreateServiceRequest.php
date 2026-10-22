<?php

declare(strict_types=1);

namespace App\Api\Application\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Datos de alta de un servicio desde la API.
 *
 * ## Tipos simples a propósito
 *
 * Los importes llegan como texto («29,90») y las fechas como ISO-8601. El
 * motivo es que el deserializador no sabe construir un `Money` (su constructor
 * es privado y tiene su propia gramática) ni decidir qué moneda aplica a un
 * importe suelto: eso lo sabe el caso de uso, que además devuelve un error
 * comprensible cuando el texto no es un importe válido.
 *
 * ## Validación
 *
 * Las reglas de forma (longitudes, rangos) las pone el Validador; las reglas
 * de negocio (la moneda, la fecha, el importe) las pone el caso de uso.
 */
final readonly class CreateServiceRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'El nombre del servicio es obligatorio.')]
        #[Assert\Length(max: 160, maxMessage: 'El nombre no puede pasar de 160 caracteres.')]
        public string $name,
        public ?string $currency = null,
        #[SerializedName('billing_period')]
        public ?string $billingPeriod = null,
        #[SerializedName('billing_interval_count')]
        #[Assert\Range(
            min: 1,
            max: 52,
            notInRangeMessage: 'El intervalo de facturación debe estar entre 1 y 52.',
        )]
        public ?int $billingIntervalCount = null,
        public ?string $amount = null,
        #[SerializedName('plan_name')]
        #[Assert\Length(max: 160, maxMessage: 'El nombre del plan no puede pasar de 160 caracteres.')]
        public ?string $planName = null,
        #[SerializedName('started_at')]
        public ?string $startedAt = null,
        #[SerializedName('next_charge_at')]
        public ?string $nextChargeAt = null,
        #[SerializedName('renewal_at')]
        public ?string $renewalAt = null,
        #[SerializedName('notice_period_days')]
        #[Assert\Range(
            min: 1,
            max: 365,
            notInRangeMessage: 'El preaviso debe estar entre 1 y 365 días.',
        )]
        public ?int $noticePeriodDays = null,
        #[SerializedName('auto_renews')]
        public ?bool $autoRenews = null,
        #[SerializedName('provider_id')]
        public ?string $providerId = null,
        #[SerializedName('category_id')]
        public ?string $categoryId = null,
        #[SerializedName('payment_method_label')]
        #[Assert\Length(max: 100, maxMessage: 'El método de pago no puede pasar de 100 caracteres.')]
        public ?string $paymentMethodLabel = null,
        public ?string $notes = null,
    ) {
    }
}
