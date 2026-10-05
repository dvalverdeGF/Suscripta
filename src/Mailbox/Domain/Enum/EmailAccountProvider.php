<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Enum;

/**
 * Cómo entra el correo en el sistema (ARCHITECTURE.md §4.5).
 *
 * `IMAP` es el caso general y el único implementado: cualquier servidor que
 * hable IMAP estándar sirve, sea Gmail, Microsoft 365, un hosting propio o un
 * buzón corporativo. `GMAIL` y `MICROSOFT` están reservados para el día en que
 * exista una integración OAuth específica; hoy no se usan y no condicionan el
 * diseño (D-23).
 */
enum EmailAccountProvider: string
{
    case IMAP = 'imap';
    case FORWARDING = 'forwarding';
    case GMAIL = 'gmail';
    case MICROSOFT = 'microsoft';

    public function label(): string
    {
        return match ($this) {
            self::IMAP => 'IMAP',
            self::FORWARDING => 'Reenvío de correo',
            self::GMAIL => 'Gmail',
            self::MICROSOFT => 'Microsoft 365',
        };
    }

    public function isImplemented(): bool
    {
        return self::IMAP === $this;
    }
}
