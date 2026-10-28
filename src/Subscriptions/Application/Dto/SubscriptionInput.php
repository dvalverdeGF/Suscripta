<?php

declare(strict_types=1);

namespace App\Subscriptions\Application\Dto;

use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Datos de alta o edición de una suscripción, ya validados por el formulario.
 */
final readonly class SubscriptionInput
{
    public function __construct(
        public string $name,
        public Currency $currency,
        public BillingPeriod $billingPeriod,
        public int $billingIntervalCount = 1,
        public ?DateTimeImmutable $startedAt = null,
        public ?Uuid $planId = null,
        public ?Uuid $serviceId = null,
        public ?string $externalReference = null,
    ) {
    }
}
