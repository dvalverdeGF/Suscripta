<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Imap;

use App\Mailbox\Domain\Enum\ImapEncryption;
use SensitiveParameter;

use function sprintf;

/**
 * Parámetros efímeros para abrir una conexión IMAP.
 *
 * Vive solo en memoria durante la operación: la contraseña se descifra justo
 * antes de conectar y se descarta al terminar (SECURITY.md §3).
 */
final readonly class ImapConnectionConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public ImapEncryption $encryption,
        public string $username,
        #[SensitiveParameter]
        public string $password,
        public int $timeout = 30,
    ) {
    }

    /**
     * Representación segura para logs: nunca expone la contraseña.
     */
    public function describe(): string
    {
        return sprintf('%s@%s:%d (%s)', $this->username, $this->host, $this->port, $this->encryption->value);
    }
}
