<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Enum;

/**
 * Cuánto urge el aviso. Determina el color en la bandeja y si se envía correo.
 */
enum AlertSeverity: string
{
    case INFO = 'info';
    case WARNING = 'warning';
    case CRITICAL = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::INFO => 'Información',
            self::WARNING => 'Atención',
            self::CRITICAL => 'Urgente',
        };
    }

    /**
     * Solo lo urgente justifica interrumpir al usuario por correo.
     */
    public function warrantsEmail(): bool
    {
        return self::CRITICAL === $this;
    }
}
