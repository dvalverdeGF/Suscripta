<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Enum;

/**
 * Por dónde ha entrado un mensaje al sistema (ARCHITECTURE.md §4.5, D-21).
 *
 * Las dos vías de ingesta producen el mismo `EmailMessage`, de modo que el
 * pipeline no distingue entre ellas. Lo único que cambia es de dónde sale el
 * cuerpo: por IMAP se descarga bajo demanda, y por reenvío llega con el propio
 * mensaje.
 */
enum EmailMessageSource: string
{
    case IMAP = 'imap';
    case FORWARDING = 'forwarding';

    public function label(): string
    {
        return match ($this) {
            self::IMAP => 'Buzón conectado',
            self::FORWARDING => 'Reenviado',
        };
    }

    /**
     * ¿Hay que pedirle el cuerpo al servidor IMAP?
     */
    public function requiresMailboxAccess(): bool
    {
        return self::IMAP === $this;
    }
}
