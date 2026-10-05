<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Enum;

/**
 * Por dónde se entrega un aviso.
 *
 * `IN_APP` es el canal por defecto y el único que no depende de terceros: el
 * aviso vive en la base de datos y se lee en la bandeja. `EMAIL` es opcional y
 * se puede desactivar por tipo (SECURITY.md §4.5).
 */
enum NotificationChannel: string
{
    case IN_APP = 'in_app';
    case EMAIL = 'email';

    public function label(): string
    {
        return match ($this) {
            self::IN_APP => 'En la aplicación',
            self::EMAIL => 'Por correo electrónico',
        };
    }
}
