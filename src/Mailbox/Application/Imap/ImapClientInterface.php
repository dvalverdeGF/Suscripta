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
     * `UIDVALIDITY` de la carpeta.
     *
     * Es el número que el servidor cambia cuando renumera los UID. Sin
     * comprobarlo, un cursor guardado podría hacer que la sincronización se
     * saltara mensajes nuevos en silencio (ARCHITECTURE.md §13.2).
     *
     * @throws ImapConnectionException
     * @throws ImapFetchException
     */
    public function getUidValidity(ImapConnectionConfig $config, string $folder): int;

    /**
     * Cabeceras de los mensajes con UID **mayor** que `$afterUid`, de más
     * antiguo a más reciente.
     *
     * Es la lectura incremental: solo lo que ha llegado desde la última vez.
     *
     * @return list<ImapMessageHeader>
     *
     * @throws ImapConnectionException
     * @throws ImapFetchException
     */
    public function fetchHeadersAfter(
        ImapConnectionConfig $config,
        string $folder,
        int $afterUid,
        int $limit = 200,
    ): array;

    /**
     * Cabeceras de los mensajes con UID **menor** que `$beforeUid`, de más
     * reciente a más antiguo.
     *
     * Es la lectura hacia atrás del backfill progresivo: recupera histórico sin
     * bloquear la llegada de correo nuevo.
     *
     * @return list<ImapMessageHeader>
     *
     * @throws ImapConnectionException
     * @throws ImapFetchException
     */
    public function fetchHeadersBefore(
        ImapConnectionConfig $config,
        string $folder,
        int $beforeUid,
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
