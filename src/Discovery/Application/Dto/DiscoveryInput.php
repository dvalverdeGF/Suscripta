<?php

declare(strict_types=1);

namespace App\Discovery\Application\Dto;

use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Datos que el usuario confirma o corrige antes de aceptar una propuesta.
 *
 * Es un DTO y no la entidad `Discovery` porque el usuario puede cambiar
 * cualquier campo: lo que se guarda es lo que él ha decidido, no lo que el
 * sistema había adivinado. La propuesta original queda intacta en
 * `Discovery.proposedData` para poder medir la precisión del pipeline.
 */
final readonly class DiscoveryInput
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
        public ?string $notes = null,
    ) {
    }
}
