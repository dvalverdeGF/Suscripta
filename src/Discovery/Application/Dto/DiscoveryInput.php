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

    /**
     * Copia con otro proveedor asignado.
     */
    public function withProviderId(?Uuid $providerId): self
    {
        return new self(
            name: $this->name,
            currency: $this->currency,
            billingPeriod: $this->billingPeriod,
            billingIntervalCount: $this->billingIntervalCount,
            amount: $this->amount,
            providerId: $providerId,
            categoryId: $this->categoryId,
            planName: $this->planName,
            startedAt: $this->startedAt,
            nextChargeAt: $this->nextChargeAt,
            renewalAt: $this->renewalAt,
            noticePeriodDays: $this->noticePeriodDays,
            autoRenews: $this->autoRenews,
            notes: $this->notes,
        );
    }

    /**
     * Acepta la propuesta tal cual la hizo el pipeline.
     *
     * Es lo que se usa en la revisión por lotes: el usuario ha marcado varias
     * propuestas sin tocar los datos, así que se confirma exactamente lo que
     * el sistema propuso. Cualquier corrección se hace en la pantalla de
     * revisión de una propuesta concreta.
     */
    public static function fromProposal(ProposedData $proposed): self
    {
        $renewalAt = $proposed->renewalAt();

        return new self(
            name: $proposed->name() ?? 'Servicio sin nombre',
            currency: $proposed->currency(),
            billingPeriod: $proposed->billingPeriod(),
            amount: $proposed->amount(),
            planName: $proposed->planName(),
            startedAt: $proposed->startedAt(),
            nextChargeAt: $renewalAt ?? $proposed->dueAt(),
            renewalAt: $renewalAt,
        );
    }
}
