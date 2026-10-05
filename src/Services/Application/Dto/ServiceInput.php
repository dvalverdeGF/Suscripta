<?php

declare(strict_types=1);

namespace App\Services\Application\Dto;

use App\Services\Domain\Enum\ServiceSource;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Datos de entrada para crear o editar un servicio.
 *
 * Es un DTO y no la entidad porque el formulario, el importador y el pipeline de
 * descubrimiento alimentan el mismo caso de uso con orígenes distintos.
 */
final readonly class ServiceInput
{
    public function __construct(
        public string $name,
        public Currency $currency = Currency::EUR,
        public BillingPeriod $billingPeriod = BillingPeriod::MONTHLY,
        public int $billingIntervalCount = 1,
        public ?Money $amount = null,
        public ?Uuid $providerId = null,
        public ?Uuid $categoryId = null,
        public ?string $planName = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $nextChargeAt = null,
        public ?DateTimeImmutable $renewalAt = null,
        public ?int $noticePeriodDays = null,
        public bool $autoRenews = true,
        public ?DateTimeImmutable $commitmentEndAt = null,
        public ?string $paymentMethodLabel = null,
        public ?string $notes = null,
        public ServiceSource $source = ServiceSource::MANUAL,
    ) {
    }
}
