<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Imap\Discovery;

use App\Mailbox\Domain\Enum\ImapEncryption;

/**
 * Servidor IMAP deducido a partir de la dirección de correo.
 *
 * Es una **sugerencia**, no una verdad: la interfaz la escribe en el formulario
 * y el usuario puede cambiarla antes de guardar. Por eso el resultado lleva de
 * dónde salió —`srv`, `convención`— y la interfaz puede decir en qué se basa.
 */
final readonly class DiscoveredImapEndpoint
{
    public function __construct(
        public string $host,
        public int $port,
        public ImapEncryption $encryption,
        /** Cómo se ha encontrado: `srv` o `convención`. */
        public string $source,
    ) {
    }
}
