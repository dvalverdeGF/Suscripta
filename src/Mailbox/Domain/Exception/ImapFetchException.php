<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Exception;

use RuntimeException;

/**
 * La conexión funciona pero un mensaje o carpeta concretos no se han podido
 * leer. Es un fallo recuperable: la sincronización continúa con el resto.
 */
class ImapFetchException extends RuntimeException
{
}
