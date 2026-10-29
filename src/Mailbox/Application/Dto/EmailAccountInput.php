<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Dto;

use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\ValueObject\EmailAccountCredentials;

/**
 * Datos para conectar un buzón.
 *
 * Las credenciales viajan en claro **solo** en memoria: el caso de uso las
 * cifra antes de tocar la base de datos y las descarta al terminar
 * (SECURITY.md §3).
 *
 * `credentials` es `null` al editar un buzón sin cambiar la contraseña: se
 * conservan las que ya estaban cifradas. Al conectar es obligatorio.
 *
 * `imapHost`, `imapPort` e `imapEncryption` solo se usan con el IMAP genérico.
 * Cuando el proveedor tiene preset (Gmail, Microsoft, Yahoo, iCloud) la
 * aplicación los ignora y usa los del catálogo: el cliente no decide a qué
 * servidor se conecta la aplicación (D-93).
 */
final readonly class EmailAccountInput
{
    public function __construct(
        public string $emailAddress,
        public ?EmailAccountCredentials $credentials = null,
        public string $imapHost = '',
        public ImapEncryption $imapEncryption = ImapEncryption::SSL,
        public ?int $imapPort = null,
        public ?string $imapUsername = null,
        public string $imapFolder = 'INBOX',
        public ?string $displayName = null,
        public EmailAccountProvider $provider = EmailAccountProvider::IMAP,
    ) {
    }
}
