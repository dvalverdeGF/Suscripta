<?php

declare(strict_types=1);

namespace App\Billing\Domain\ValueObject;

use App\Billing\Domain\Enum\InvoiceStatus;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Una factura de venta emitida por la pasarela (Fase 24).
 *
 * ## Es la factura del cobro, no la factura descubierta en el correo
 *
 * Suscripta tiene dos tipos de factura y no se pueden mezclar: las de
 * `Services` son las que el cliente **recibe** de sus proveedores y se leen del
 * correo; esta es la que el cliente **recibe de Suscripta**. Comparten la
 * palabra y nada más: una se descubre, la otra se emite. Por eso esta vive en
 * `Billing` y no en `Services` (D-86).
 *
 * ## El importe lo dice la pasarela
 *
 * Se copia el total cobrado y no se recalcula con el precio del catálogo: con
 * impuestos, cupones o prorrateos, el total no es «precio × cantidad». Copiar
 * lo que se cobró es la única forma de que la factura del panel cuadre con el
 * extracto del cliente.
 */
final readonly class GatewayInvoice
{
    public function __construct(
        public string $externalId,
        public ?string $number,
        public int $amountMinor,
        public Currency $currency,
        public InvoiceStatus $status,
        public DateTimeImmutable $issuedAt,
        public ?DateTimeImmutable $paidAt = null,
        public ?string $hostedUrl = null,
        public ?string $pdfUrl = null,
        public ?string $subscriptionExternalId = null,
        public ?Uuid $organizationId = null,
    ) {
    }

    public function amount(): Money
    {
        return Money::of($this->amountMinor, $this->currency);
    }
}
