<?php

declare(strict_types=1);

namespace App\Processing\Domain\Dto;

use App\Documents\Domain\Enum\DocumentType;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;

/**
 * Resultado normalizado de la extracción (ARCHITECTURE.md §13.6).
 *
 * Es un **DTO, no una entidad**: se persiste como JSON en
 * `Discovery.proposedData` y, más adelante, en `Document`. Su forma evolucionará
 * con los extractores, y una tabla propia obligaría a migrar cada vez que se
 * añade una señal (D-14).
 *
 * Todos los campos son opcionales salvo `tier` y `confidence`: la extracción
 * determinista casi nunca lo rellena todo, y fingir lo contrario sería peor que
 * declarar la incertidumbre.
 */
final readonly class ExtractedDocument
{
    /**
     * @param array<string, mixed> $rawSignals coincidencias concretas (qué regla, qué fragmento)
     */
    public function __construct(
        public ExtractionTier $tier,
        public float $confidence,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $invoiceNumber = null,
        public ?DateTimeImmutable $invoiceDate = null,
        public ?DateTimeImmutable $dueDate = null,
        public ?BillingPeriod $billingPeriod = null,
        public ?string $sender = null,
        public ?string $senderDomain = null,
        public ?string $subject = null,
        public DocumentType $documentType = DocumentType::OTHER,
        public ?string $providerName = null,
        public ?string $serviceName = null,
        public ?string $plan = null,
        public ?DateTimeImmutable $renewalDate = null,
        public array $rawSignals = [],
    ) {
    }

    /**
     * ¿Hay suficiente para proponer algo al usuario?
     *
     * Un importe sin periodicidad no permite calcular un coste recurrente, y un
     * proveedor sin importe no permite decir cuánto cuesta. Se exige lo mínimo
     * para que la propuesta sea útil, no solo plausible.
     */
    public function isActionable(): bool
    {
        return null !== $this->amountMinor
            && null !== $this->providerName
            && null !== $this->billingPeriod
            && $this->billingPeriod->isRecurring();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amountMinor' => $this->amountMinor,
            'currency' => $this->currency,
            'invoiceNumber' => $this->invoiceNumber,
            'invoiceDate' => $this->invoiceDate?->format('Y-m-d'),
            'dueDate' => $this->dueDate?->format('Y-m-d'),
            'billingPeriod' => $this->billingPeriod?->value,
            'sender' => $this->sender,
            'senderDomain' => $this->senderDomain,
            'subject' => $this->subject,
            'documentType' => $this->documentType->value,
            'providerName' => $this->providerName,
            'serviceName' => $this->serviceName,
            'plan' => $this->plan,
            'renewalDate' => $this->renewalDate?->format('Y-m-d'),
            'confidence' => $this->confidence,
            'tier' => $this->tier->value,
            'rawSignals' => $this->rawSignals,
        ];
    }
}
