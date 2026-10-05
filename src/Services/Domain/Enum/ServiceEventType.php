<?php

declare(strict_types=1);

namespace App\Services\Domain\Enum;

use function in_array;

/**
 * Tipos de evento del ciclo de vida de un servicio.
 *
 * `ServiceEvent` es append-only: alimenta la pregunta "¿qué ha cambiado?" del
 * dashboard y es la base de las alertas de cambio (ARCHITECTURE.md §4.3).
 */
enum ServiceEventType: string
{
    case CREATED = 'created';
    case PRICE_CHANGED = 'price_changed';
    case PLAN_CHANGED = 'plan_changed';
    case RENEWED = 'renewed';
    case PAUSED = 'paused';
    case RESUMED = 'resumed';
    case CANCELLED = 'cancelled';
    case DOCUMENT_ADDED = 'document_added';
    case DISCOVERED = 'discovered';
    case NOTE_ADDED = 'note_added';

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Servicio creado',
            self::PRICE_CHANGED => 'Cambio de precio',
            self::PLAN_CHANGED => 'Cambio de plan',
            self::RENEWED => 'Renovación',
            self::PAUSED => 'Servicio pausado',
            self::RESUMED => 'Servicio reactivado',
            self::CANCELLED => 'Servicio cancelado',
            self::DOCUMENT_ADDED => 'Documento añadido',
            self::DISCOVERED => 'Descubierto en el correo',
            self::NOTE_ADDED => 'Nota añadida',
        };
    }

    /**
     * Los eventos que el usuario debe ver destacados en "qué ha cambiado".
     */
    public function isNoteworthy(): bool
    {
        return in_array($this, [
            self::PRICE_CHANGED,
            self::PLAN_CHANGED,
            self::CANCELLED,
            self::RENEWED,
            self::DISCOVERED,
        ], true);
    }
}
