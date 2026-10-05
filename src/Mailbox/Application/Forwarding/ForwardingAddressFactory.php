<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Forwarding;

use function bin2hex;
use function random_bytes;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Genera direcciones de ingesta por reenvío (D-21).
 *
 * La dirección es un **secreto**: quien la conozca puede intentar inyectar
 * correo. Por eso el token es aleatorio y no derivado de nada (nada de
 * `facturas-de-acme@…`), y por eso se puede rotar.
 */
final readonly class ForwardingAddressFactory
{
    public function __construct(
        private string $domain,
        private string $prefix = 'inbox',
    ) {
    }

    public function generate(): string
    {
        return sprintf(
            '%s-%s@%s',
            trim($this->prefix),
            bin2hex(random_bytes(16)),
            strtolower(trim($this->domain)),
        );
    }
}
