<?php

declare(strict_types=1);

namespace App\Documents\Domain\Enum;

/**
 * Cómo llegó la factura al historial.
 *
 * Se guarda porque una factura puede existir **sin documento** (D-19: hay
 * cobros que solo conocemos por el cuerpo del correo), y en ese caso no hay
 * ningún `Document.source` que nos diga si la detectó el sistema o la apuntó
 * el usuario. Es también la métrica que mide la propuesta de valor
 * (PRODUCT.md §12): qué parte del historial la descubrió el producto.
 */
enum InvoiceSource: string
{
    case MANUAL = 'manual';
    case EMAIL_DISCOVERY = 'email_discovery';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Registrada a mano',
            self::EMAIL_DISCOVERY => 'Detectada en tu correo',
        };
    }
}
