<?php

declare(strict_types=1);

namespace App\Documents\Domain\Enum;

/**
 * Estado de cobro de una factura (ARCHITECTURE.md §4.4).
 *
 * `UNKNOWN` es el valor por defecto y no un fallo: la mayoría de facturas
 * llegan sin que podamos saber si ya se han cobrado, y preferimos decirlo a
 * inventarnos un estado.
 */
enum InvoiceStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';
    case UNKNOWN = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::PAID => 'Pagada',
            self::FAILED => 'Fallida',
            self::REFUNDED => 'Devuelta',
            self::UNKNOWN => 'Sin determinar',
        };
    }

    /**
     * Solo una factura pagada confirma un cobro real. Es lo que permite
     * distinguir "me han facturado" de "me han cobrado".
     */
    public function confirmsPayment(): bool
    {
        return self::PAID === $this;
    }
}
