<?php

declare(strict_types=1);

namespace App\Api\Application\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Confirmación de una propuesta de descubrimiento desde la API.
 *
 * ## Todo es opcional salvo el nombre
 *
 * Si el integrador solo manda `name`, se confirma la propuesta tal cual la
 * hizo el pipeline: el resto de campos se toman de lo que la extracción
 * dedujo. Si además manda campos, son correcciones y sustituyen a los
 * deducidos. Es el mismo criterio que usa la pantalla de revisión: lo que se
 * guarda es lo que el usuario ha decidido.
 */
final readonly class ConfirmProposalRequest
{
    public function __construct(
        #[Assert\Length(max: 200, maxMessage: 'El nombre no puede pasar de 200 caracteres.')]
        public ?string $name = null,
        public ?string $currency = null,
        #[SerializedName('billing_period')]
        public ?string $billingPeriod = null,
        #[SerializedName('billing_interval_count')]
        public ?int $billingIntervalCount = null,
        public ?string $amount = null,
        #[SerializedName('plan_name')]
        public ?string $planName = null,
        #[SerializedName('started_at')]
        public ?string $startedAt = null,
        #[SerializedName('next_charge_at')]
        public ?string $nextChargeAt = null,
        #[SerializedName('renewal_at')]
        public ?string $renewalAt = null,
        #[SerializedName('notice_period_days')]
        public ?int $noticePeriodDays = null,
        #[SerializedName('auto_renews')]
        public ?bool $autoRenews = null,
        public ?string $notes = null,
        #[SerializedName('provider_id')]
        public ?string $providerId = null,
        #[SerializedName('category_id')]
        public ?string $categoryId = null,
    ) {
    }
}
