<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Enum;

/**
 * Ciclo de vida de un aviso.
 *
 * `ACKNOWLEDGED` y `DISMISSED` se separan a propósito: «lo he visto» y «no me
 * interesa» son decisiones distintas, y solo la segunda debe silenciar futuros
 * avisos del mismo tipo para el mismo servicio.
 */
enum AlertStatus: string
{
    case OPEN = 'open';
    case ACKNOWLEDGED = 'acknowledged';
    case DISMISSED = 'dismissed';
    case RESOLVED = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Abierto',
            self::ACKNOWLEDGED => 'Visto',
            self::DISMISSED => 'Descartado',
            self::RESOLVED => 'Resuelto',
        };
    }

    public function isOpen(): bool
    {
        return self::OPEN === $this;
    }

    /**
     * ¿Sigue contando para el contador de la portada?
     */
    public function needsAttention(): bool
    {
        return self::OPEN === $this;
    }
}
