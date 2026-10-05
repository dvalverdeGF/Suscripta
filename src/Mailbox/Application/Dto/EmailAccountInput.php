<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Dto;

use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Enum\ImapEncryption;

/**
 * Datos para conectar un buzón.
 *
 * La contraseña viaja en claro **solo** en memoria: el caso de uso la cifra
 * antes de tocar la base de datos y la descarta al terminar (SECURITY.md §3).
 */
final readonly class EmailAccountInput
{
    public function __construct(
        public string $emailAddress,
        public string $password,
        public string $imapHost,
        public ImapEncryption $imapEncryption = ImapEncryption::SSL,
        public ?int $imapPort = null,
        public ?string $imapUsername = null,
        public string $imapFolder = 'INBOX',
        public ?string $displayName = null,
        public EmailAccountProvider $provider = EmailAccountProvider::IMAP,
    ) {
    }
}
