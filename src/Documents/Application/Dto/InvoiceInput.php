<?php

declare(strict_types=1);

namespace App\Documents\Application\Dto;

use App\Documents\Domain\Enum\InvoiceSource;
use App\Documents\Domain\Enum\InvoiceStatus;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Los datos de un cobro documentado. Los rellena el usuario desde la interfaz o
 * el pipeline de análisis cuando reconoce una factura en un correo.
 */
final readonly class InvoiceInput
{
    public function __construct(
        public DateTimeImmutable $issuedAt,
        public Money $total,
        public ?Uuid $serviceId = null,
        public ?Uuid $providerId = null,
        public ?Uuid $documentId = null,
        public ?string $number = null,
        public ?DateTimeImmutable $periodStart = null,
        public ?DateTimeImmutable $periodEnd = null,
        public ?DateTimeImmutable $paidAt = null,
        public InvoiceStatus $status = InvoiceStatus::UNKNOWN,
        public InvoiceSource $source = InvoiceSource::MANUAL,
    ) {
    }
}
