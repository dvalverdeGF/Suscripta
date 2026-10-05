<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Enum;

/**
 * Ciclo de vida de una propuesta (ARCHITECTURE.md §4.6).
 *
 * `EDITED` es distinto de `CONFIRMED`: el usuario aceptó la idea pero corrigió
 * los datos, y esa corrección es la señal de aprendizaje más valiosa del
 * sistema (§13.12). Distinguirlas permite medir cuánto acierta el extractor.
 */
enum DiscoveryStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case EDITED = 'edited';
    case IGNORED = 'ignored';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::CONFIRMED => 'Confirmado',
            self::EDITED => 'Confirmado con cambios',
            self::IGNORED => 'Descartado',
            self::EXPIRED => 'Caducado',
        };
    }

    public function isPending(): bool
    {
        return self::PENDING === $this;
    }

    /**
     * ¿Sigue esperando una decisión del usuario?
     */
    public function isOpen(): bool
    {
        return self::PENDING === $this;
    }

    public function isResolved(): bool
    {
        return !$this->isOpen();
    }
}
