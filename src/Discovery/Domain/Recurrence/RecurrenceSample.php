<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Recurrence;

use DateTimeImmutable;

/**
 * Un cobro observado: cuándo ocurrió y, si se sabe, cuánto se cobró.
 *
 * La detección de recurrencia es determinista y sólo necesita el *cuándo* y el
 * *cuánto*. El resto (proveedor, documento, factura) es contexto y no forma
 * parte de la muestra: así el mismo detector sirve para facturas importadas,
 * para correos procesados y para historiales sintéticos de test.
 */
final readonly class RecurrenceSample
{
    public function __construct(
        public DateTimeImmutable $chargedAt,
        public ?int $amountMinor = null,
        public ?string $currency = null,
    ) {
    }
}
