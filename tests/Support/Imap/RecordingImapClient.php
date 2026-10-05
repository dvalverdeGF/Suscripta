<?php

declare(strict_types=1);

namespace App\Tests\Support\Imap;

use App\Mailbox\Application\Imap\ImapClientInterface;
use App\Mailbox\Application\Imap\ImapConnectionConfig;
use App\Mailbox\Application\Imap\ImapMessageBody;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Exception\ImapFetchException;
use DateTimeImmutable;

/**
 * Cliente IMAP de mentira, controlable desde los tests funcionales.
 *
 * Se registra en `config/services_test.yaml` en lugar de `WebklexImapClient`,
 * de modo que ninguna prueba funcional abre una conexión de red real. Además
 * de devolver datos prefijados, cuenta las llamadas: así se puede afirmar que
 * el cuerpo de un mensaje **no** se descarga cuando no hace falta (D-38).
 */
final class RecordingImapClient implements ImapClientInterface
{
    /** @var list<ImapConnectionConfig> */
    public array $testConnectionCalls = [];

    /** @var list<ImapConnectionConfig> */
    public array $listFoldersCalls = [];

    /** @var list<array{config: ImapConnectionConfig, folder: string, since: ?DateTimeImmutable, limit: int}> */
    public array $fetchHeadersCalls = [];

    /** @var list<array{config: ImapConnectionConfig, folder: string, afterUid: int, limit: int}> */
    public array $fetchHeadersAfterCalls = [];

    /** @var list<array{config: ImapConnectionConfig, folder: string, beforeUid: int, limit: int}> */
    public array $fetchHeadersBeforeCalls = [];

    /** @var list<array{config: ImapConnectionConfig, folder: string}> */
    public array $uidValidityCalls = [];

    /** @var list<array{config: ImapConnectionConfig, folder: string, uid: int}> */
    public array $fetchBodyCalls = [];

    /** @var list<string> */
    public array $folders = ['INBOX'];

    /** @var list<ImapMessageHeader> */
    public array $headers = [];

    /**
     * Cabeceras que devuelve la lectura incremental, indexadas por el UID a
     * partir del cual se piden. Si no hay entrada para un `afterUid`, se
     * devuelve `$headers`.
     *
     * @var array<int, list<ImapMessageHeader>>
     */
    public array $headersAfter = [];

    /**
     * Cabeceras que devuelve la lectura hacia atrás, indexadas por el UID por
     * debajo del cual se piden.
     *
     * @var array<int, list<ImapMessageHeader>>
     */
    public array $headersBefore = [];

    public int $uidValidity = 1;

    public ?ImapMessageBody $body = null;

    public ?string $connectionFailure = null;

    public ?string $fetchFailure = null;

    public function reset(): void
    {
        $this->testConnectionCalls = [];
        $this->listFoldersCalls = [];
        $this->fetchHeadersCalls = [];
        $this->fetchHeadersAfterCalls = [];
        $this->fetchHeadersBeforeCalls = [];
        $this->uidValidityCalls = [];
        $this->fetchBodyCalls = [];
        $this->folders = ['INBOX'];
        $this->headers = [];
        $this->headersAfter = [];
        $this->headersBefore = [];
        $this->uidValidity = 1;
        $this->body = null;
        $this->connectionFailure = null;
        $this->fetchFailure = null;
    }

    /**
     * @param list<ImapMessageHeader> $headers
     */
    public function willReturnHeaders(array $headers): void
    {
        $this->headers = $headers;
    }

    public function willReturnBody(ImapMessageBody $body): void
    {
        $this->body = $body;
    }

    /**
     * @param list<ImapMessageHeader> $headers
     */
    public function willReturnHeadersAfter(int $afterUid, array $headers): void
    {
        $this->headersAfter[$afterUid] = $headers;
    }

    /**
     * @param list<ImapMessageHeader> $headers
     */
    public function willReturnHeadersBefore(int $beforeUid, array $headers): void
    {
        $this->headersBefore[$beforeUid] = $headers;
    }

    public function willReturnUidValidity(int $uidValidity): void
    {
        $this->uidValidity = $uidValidity;
    }

    public function willFailToConnect(string $message = 'No se pudo conectar con el servidor IMAP.'): void
    {
        $this->connectionFailure = $message;
    }

    public function willFailToFetch(string $message = 'No se pudo descargar el mensaje.'): void
    {
        $this->fetchFailure = $message;
    }

    public function testConnection(ImapConnectionConfig $config): void
    {
        $this->testConnectionCalls[] = $config;

        if (null !== $this->connectionFailure) {
            throw new ImapConnectionException($this->connectionFailure);
        }
    }

    public function listFolders(ImapConnectionConfig $config): array
    {
        $this->listFoldersCalls[] = $config;

        if (null !== $this->connectionFailure) {
            throw new ImapConnectionException($this->connectionFailure);
        }

        return $this->folders;
    }

    public function fetchHeaders(
        ImapConnectionConfig $config,
        string $folder,
        ?DateTimeImmutable $since = null,
        int $limit = 200,
    ): array {
        $this->fetchHeadersCalls[] = [
            'config' => $config,
            'folder' => $folder,
            'since' => $since,
            'limit' => $limit,
        ];

        if (null !== $this->connectionFailure) {
            throw new ImapConnectionException($this->connectionFailure);
        }

        return $this->headers;
    }

    public function getUidValidity(ImapConnectionConfig $config, string $folder): int
    {
        $this->uidValidityCalls[] = ['config' => $config, 'folder' => $folder];

        if (null !== $this->connectionFailure) {
            throw new ImapConnectionException($this->connectionFailure);
        }

        return $this->uidValidity;
    }

    public function fetchHeadersAfter(
        ImapConnectionConfig $config,
        string $folder,
        int $afterUid,
        int $limit = 200,
    ): array {
        $this->fetchHeadersAfterCalls[] = [
            'config' => $config,
            'folder' => $folder,
            'afterUid' => $afterUid,
            'limit' => $limit,
        ];

        if (null !== $this->connectionFailure) {
            throw new ImapConnectionException($this->connectionFailure);
        }

        return $this->headersAfter[$afterUid] ?? $this->headers;
    }

    public function fetchHeadersBefore(
        ImapConnectionConfig $config,
        string $folder,
        int $beforeUid,
        int $limit = 200,
    ): array {
        $this->fetchHeadersBeforeCalls[] = [
            'config' => $config,
            'folder' => $folder,
            'beforeUid' => $beforeUid,
            'limit' => $limit,
        ];

        if (null !== $this->connectionFailure) {
            throw new ImapConnectionException($this->connectionFailure);
        }

        return $this->headersBefore[$beforeUid] ?? [];
    }

    public function fetchBody(ImapConnectionConfig $config, string $folder, int $uid): ImapMessageBody
    {
        $this->fetchBodyCalls[] = ['config' => $config, 'folder' => $folder, 'uid' => $uid];

        if (null !== $this->fetchFailure) {
            throw new ImapFetchException($this->fetchFailure);
        }

        return $this->body ?? new ImapMessageBody(uid: $uid, textBody: '', htmlBody: '');
    }
}
