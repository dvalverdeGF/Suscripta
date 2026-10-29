<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\ValueObject;

use App\Mailbox\Domain\Enum\ImapEncryption;

/**
 * Datos de conexión que ya vienen dados por el proveedor de correo.
 *
 * Gmail, Microsoft, Yahoo e iCloud publican su servidor IMAP y no lo cambian:
 * pedirle al usuario que lo busque y lo teclee es una fuente de errores
 * gratuita. Cuando el proveedor tiene preset, la aplicación **ignora** lo que
 * llegue del formulario y usa estos valores (D-93).
 *
 * `IMAP` genérico no tiene preset a propósito: ahí el servidor es un dato del
 * usuario y no hay nada que adivinar.
 */
final readonly class MailProviderPreset
{
    public function __construct(
        public string $host,
        public int $port,
        public ImapEncryption $encryption,
        /** Página oficial donde el proveedor genera la contraseña de aplicación. */
        public ?string $appPasswordUrl = null,
    ) {
    }
}
