<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Imap\Discovery;

use App\Mailbox\Application\Imap\Discovery\ImapEndpointProbeInterface;
use App\Mailbox\Domain\Enum\ImapEncryption;

use function fclose;
use function fgets;
use function fwrite;
use function is_resource;
use function sprintf;
use function str_starts_with;

use const STREAM_CLIENT_CONNECT;
use const STREAM_CRYPTO_METHOD_TLS_CLIENT;

use function stream_set_timeout;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function strtoupper;
use function trim;

/**
 * Comprueba si un host habla IMAP de verdad.
 *
 * Abrir el puerto no basta: un servidor web puede tener el 993 abierto y
 * contestar cualquier cosa. Se hace el saludo TLS, se lee el banner y se exige
 * que empiece por `* OK`, que es lo primero que manda un servidor IMAP.
 *
 * Los tiempos son cortos a propósito. La detección la espera un usuario delante
 * de un formulario: es mejor decir «no lo he encontrado» en tres segundos que
 * acertar en treinta.
 */
final readonly class SocketImapProbe implements ImapEndpointProbeInterface
{
    public function __construct(
        private float $connectTimeout = 1.5,
        private int $readTimeout = 1,
    ) {
    }

    public function responds(string $host, int $port, ImapEncryption $encryption): bool
    {
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errorCode,
            $errorMessage,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT,
        );

        if (!is_resource($socket)) {
            return false;
        }

        try {
            return match ($encryption) {
                ImapEncryption::NONE => $this->greets($socket),
                // En SSL el banner llega **después** del saludo TLS: hay que
                // negociarlo antes de leer nada.
                ImapEncryption::SSL => $this->enableTls($socket) && $this->greets($socket),
                ImapEncryption::STARTTLS => $this->startTls($socket),
            };
        } finally {
            fclose($socket);
        }
    }

    /**
     * STARTTLS: se saluda en claro, se pide el cambio de cifrado y se comprueba
     * que el servidor lo acepta antes de intentarlo.
     */
    private function startTls(mixed $socket): bool
    {
        if (!$this->greets($socket)) {
            return false;
        }

        fwrite($socket, "a1 STARTTLS\r\n");

        if (!str_starts_with(strtoupper($this->readLine($socket)), 'A1 OK')) {
            return false;
        }

        return $this->enableTls($socket);
    }

    private function enableTls(mixed $socket): bool
    {
        return false !== @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    }

    private function greets(mixed $socket): bool
    {
        return str_starts_with(strtoupper($this->readLine($socket)), '* OK');
    }

    private function readLine(mixed $socket): string
    {
        stream_set_timeout($socket, $this->readTimeout);

        $line = fgets($socket, 512);

        return false === $line ? '' : trim($line);
    }
}
