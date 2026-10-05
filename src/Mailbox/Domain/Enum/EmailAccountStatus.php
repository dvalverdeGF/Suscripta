<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Enum;

/**
 * Estado de la conexión con el buzón.
 *
 * `ERROR` no borra nada: la cuenta sigue existiendo con sus mensajes y sus
 * descubrimientos, y el usuario puede corregir las credenciales y reintentar.
 */
enum EmailAccountStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case ERROR = 'error';
    case DISABLED = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente de comprobar',
            self::ACTIVE => 'Conectada',
            self::ERROR => 'Con error',
            self::DISABLED => 'Desactivada',
        };
    }

    public function canSync(): bool
    {
        return self::ACTIVE === $this;
    }
}
