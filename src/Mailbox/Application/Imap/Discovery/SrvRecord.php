<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Imap\Discovery;

/**
 * Un registro SRV ya resuelto.
 *
 * `target` viene con el punto final que usan los DNS (`imap.ejemplo.com.`) y se
 * guarda sin él: el punto final es sintaxis de la zona, no parte del nombre del
 * servidor, y dejarlo produce un certificado TLS que no valida.
 */
final readonly class SrvRecord
{
    public string $target;

    public function __construct(string $target, public int $port)
    {
        $this->target = rtrim($target, '.');
    }
}
