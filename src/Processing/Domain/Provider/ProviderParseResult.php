<?php

declare(strict_types=1);

namespace App\Processing\Domain\Provider;

use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;

/**
 * Lo que un parser de proveedor ha conseguido leer (ARCHITECTURE.md §13.7).
 *
 * Es un resultado **parcial** a propósito: un parser puede reconocer el importe
 * y el número de factura sin saber la periodicidad, y eso sigue siendo útil
 * porque el extractor genérico puede completar el resto. Devolver `null` en
 * lugar de un objeto incompleto obligaría a cada parser a saberlo todo.
 *
 * `confidence` es la del parser, no la del documento: un parser de código que
 * ha reconocido su plantilla está muy seguro de los campos que ha leído, pero
 * no de los que ha dejado vacíos.
 */
final readonly class ProviderParseResult
{
    /**
     * @param array<string, mixed> $signals qué regla concreta coincidió
     */
    public function __construct(
        public string $parserKey,
        public float $confidence,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $invoiceNumber = null,
        public ?DateTimeImmutable $invoiceDate = null,
        public ?DateTimeImmutable $dueDate = null,
        public ?BillingPeriod $billingPeriod = null,
        public ?string $plan = null,
        public ?string $serviceName = null,
        public ?DateTimeImmutable $renewalDate = null,
        public array $signals = [],
    ) {
    }

    /**
     * ¿Ha leído algo, o solo ha reconocido el formato?
     *
     * Un parser que devuelve un resultado sin ningún campo es peor que no
     * devolver nada: haría que el pipeline dejara de intentar el extractor
     * genérico y la IA para acabar con un documento vacío.
     */
    public function hasData(): bool
    {
        return null !== $this->amountMinor
            || null !== $this->invoiceNumber
            || null !== $this->billingPeriod
            || null !== $this->invoiceDate
            || null !== $this->plan;
    }
}
