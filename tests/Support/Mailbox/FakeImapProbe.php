<?php

declare(strict_types=1);

namespace App\Tests\Support\Mailbox;

use App\Mailbox\Application\Imap\Discovery\ImapEndpointProbeInterface;
use App\Mailbox\Domain\Enum\ImapEncryption;

use function in_array;
use function sprintf;

/**
 * Sonda IMAP que responde lo que el test le dice.
 *
 * Abrir sockets de verdad en una prueba funcional la haría depender de la red y
 * del cortafuegos de quien la ejecute.
 */
final class FakeImapProbe implements ImapEndpointProbeInterface
{
    /**
     * Endpoints que «responden», con la clave `host:puerto`.
     *
     * @var list<string>
     */
    public array $responding = [];

    /** @var list<string> */
    public array $probes = [];

    public function responds(string $host, int $port, ImapEncryption $encryption): bool
    {
        $this->probes[] = sprintf('%s:%d', $host, $port);

        return in_array(sprintf('%s:%d', $host, $port), $this->responding, true);
    }

    public function reset(): void
    {
        $this->responding = [];
        $this->probes = [];
    }
}
