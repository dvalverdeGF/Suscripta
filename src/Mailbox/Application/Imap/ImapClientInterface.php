<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Imap;

use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Exception\ImapFetchException;
use DateTimeImmutable;

/**
 * Acceso de solo lectura a un buzón IMAP (D-05, D-23).
 *
 * El dominio no conoce `webklex/php-imap`: esta interfaz es la única puerta de
 * entrada al correo, y su contrato es deliberadamente estrecho para que sea
 * imposible escribir en el buzón desde la aplicación (SECURITY.md §2).
 */
interface ImapClientInterface
{
    /**
     * Abre y cierra una conexión para comprobar que las credenciales sirven.
     *
     * @throws ImapConnectionException
     */
    public function testConnection(ImapConnectionConfig $config): void;

    /**
     * @return list<string>
     *
     * @throws ImapConnectionException
     */
    public function listFolders(ImapConnectionConfig $config): array;

    /**
     * Cabeceras de los mensajes de una carpeta, de más reciente a más antiguo.
     *
     * No descarga cuerpos ni marca los mensajes como leídos.
     *
     * @return list<ImapMessageHeader>
     *
     * @throws ImapConnectionException
     */
    public function fetchHeaders(
        ImapConnectionConfig $config,
        string $folder,
        ?DateTimeImmutable $since = null,
        int $limit = 200,
    ): array;

    /**
     * Cuerpo de un mensaje concreto, sin marcarlo como leído.
     *
     * @throws ImapConnectionException
     * @throws ImapFetchException
     */
    public function fetchBody(ImapConnectionConfig $config, string $folder, int $uid): ImapMessageBody;
}
