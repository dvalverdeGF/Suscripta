<?php

declare(strict_types=1);

namespace App\Documents\UI\Form;

use App\Documents\Application\Dto\InvoiceInput;
use App\Documents\Domain\Enum\InvoiceSource;
use App\Documents\Domain\Enum\InvoiceStatus;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Objeto mutable que rellena el formulario de factura.
 *
 * Igual que en el resto de formularios, los escalares son anulables: Symfony 8
 * traduce la cadena vacía a `null` y las restricciones del tipo son las que
 * impiden que ese `null` llegue al dominio.
 */
final class InvoiceFormData
{
    public ?DateTimeImmutable $issuedAt = null;
    public ?Money $total = null;
    public ?Currency $currency = Currency::EUR;
    public ?string $number = null;
    public ?Uuid $serviceId = null;
    public ?Uuid $providerId = null;
    public ?DateTimeImmutable $periodStart = null;
    public ?DateTimeImmutable $periodEnd = null;
    public ?DateTimeImmutable $paidAt = null;
    public ?InvoiceStatus $status = InvoiceStatus::UNKNOWN;
    public ?InvoiceSource $source = InvoiceSource::MANUAL;

    public function toInput(?Uuid $documentId = null): InvoiceInput
    {
        return new InvoiceInput(
            issuedAt: $this->issuedAt ?? new DateTimeImmutable(),
            total: $this->total ?? Money::zero($this->currency ?? Currency::EUR),
            serviceId: $this->serviceId,
            providerId: $this->providerId,
            documentId: $documentId,
            number: $this->number,
            periodStart: $this->periodStart,
            periodEnd: $this->periodEnd,
            paidAt: $this->paidAt,
            status: $this->status ?? InvoiceStatus::UNKNOWN,
            source: $this->source ?? InvoiceSource::MANUAL,
        );
    }
}
