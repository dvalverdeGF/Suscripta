<?php

declare(strict_types=1);

namespace App\Services\Domain\Enum;

/**
 * Cómo llegó el servicio al inventario.
 *
 * Se guarda para poder medir la propuesta de valor: qué porcentaje del
 * inventario lo descubrió el sistema y no el usuario a mano (PRODUCT.md §12).
 */
enum ServiceSource: string
{
    case MANUAL = 'manual';
    case EMAIL_DISCOVERY = 'email_discovery';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Añadido a mano',
            self::EMAIL_DISCOVERY => 'Descubierto en tu correo',
        };
    }
}
