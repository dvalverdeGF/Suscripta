<?php

declare(strict_types=1);

namespace App\Webhooks\Domain\Enum;

/**
 * Estado de una entrega de webhook (Fase 20).
 *
 * El ciclo es corto y no tiene vuelta atrás:
 *
 * ```
 * pending ──► delivered
 *    │
 *    ├────► pending (reintento programado)
 *    └────► abandoned (se agotó el plazo)
 * ```
 *
 * `failed` **no** existe como estado final: un intento fallido vuelve a
 * `pending` con la fecha del siguiente intento, y solo se rinde cuando pasa el
 * plazo de abandono. Así el estado de la fila responde siempre a la pregunta
 * «¿esto todavía se va a intentar?», que es la única que importa en el panel.
 */
enum DeliveryStatus: string
{
    case PENDING = 'pending';
    case DELIVERED = 'delivered';
    case ABANDONED = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::DELIVERED => 'Entregado',
            self::ABANDONED => 'Descartado',
        };
    }

    public function isPending(): bool
    {
        return self::PENDING === $this;
    }

    public function isDelivered(): bool
    {
        return self::DELIVERED === $this;
    }
}
