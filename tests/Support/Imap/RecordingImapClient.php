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

    /** @var list<array{config: ImapConnectionConfig, folder: string, uid: int}> */
    public array $fetchBodyCalls = [];

    /** @var list<string> */
    public array $folders = ['INBOX'];

    /** @var list<ImapMessageHeader> */
    public array $headers = [];

    public ?ImapMessageBody $body = null;

    public ?string $connectionFailure = null;

    public ?string $fetchFailure = null;

    public function reset(): void
    {
        $this->testConnectionCalls = [];
        $this->listFoldersCalls = [];
        $this->fetchHeadersCalls = [];
        $this->fetchBodyCalls = [];
        $this->folders = ['INBOX'];
        $this->headers = [];
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

    public function fetchBody(ImapConnectionConfig $config, string $folder, int $uid): ImapMessageBody
    {
        $this->fetchBodyCalls[] = ['config' => $config, 'folder' => $folder, 'uid' => $uid];

        if (null !== $this->fetchFailure) {
            throw new ImapFetchException($this->fetchFailure);
        }

        return $this->body ?? new ImapMessageBody(uid: $uid, textBody: '', htmlBody: '');
    }
}
