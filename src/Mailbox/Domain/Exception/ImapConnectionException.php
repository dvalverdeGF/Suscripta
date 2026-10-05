<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Exception;

use RuntimeException;

/**
 * No se ha podido abrir o mantener la conexión con el servidor IMAP.
 *
 * El mensaje es apto para mostrarse al usuario: nunca incluye la contraseña ni
 * el volcado del protocolo (SECURITY.md §3).
 */
class ImapConnectionException extends RuntimeException
{
}
