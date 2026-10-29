<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Imap\Discovery;

use App\Mailbox\Domain\Enum\ImapEncryption;

/**
 * Comprueba si en un host y un puerto hay un servidor IMAP que responde.
 *
 * Es lo que convierte «el servidor suele llamarse `imap.<dominio>`» en un dato:
 * se abre la conexión, se hace el saludo TLS y se espera el banner `* OK`. Un
 * puerto abierto que no saluda en IMAP no sirve de nada, y proponerlo sería peor
 * que no proponer ninguno.
 *
 * Es un puerto para poder probar la detección sin red.
 */
interface ImapEndpointProbeInterface
{
    public function responds(string $host, int $port, ImapEncryption $encryption): bool;
}
