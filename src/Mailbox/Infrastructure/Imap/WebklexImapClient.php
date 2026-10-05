<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Imap;

use App\Mailbox\Application\Imap\ImapClientInterface;
use App\Mailbox\Application\Imap\ImapConnectionConfig;
use App\Mailbox\Application\Imap\ImapMessageBody;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Exception\ImapFetchException;
use DateTimeImmutable;

use function in_array;
use function is_array;
use function is_string;
use function max;
use function min;
use function preg_match_all;
use function sprintf;
use function str_contains;
use function strtolower;

use Throwable;

use function trim;

use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;

/**
 * Adaptador de `webklex/php-imap` (D-05).
 *
 * Es la única clase del proyecto que conoce la librería. Todo lo que sale de
 * aquí son DTOs propios, de modo que cambiar de cliente IMAP no toca el
 * dominio.
 *
 * Garantías de solo lectura (SECURITY.md §2):
 * - `FT_PEEK` en todas las lecturas: ningún mensaje se marca como leído.
 * - `EXAMINE` en lugar de `SELECT` cuando la librería lo permite.
 * - No existe ninguna operación de escritura en esta clase.
 */
final class WebklexImapClient implements ImapClientInterface
{
    /**
     * Tope duro de mensajes por sincronización. Evita que un buzón con cien
     * mil correos convierta la primera sincronización en un incidente.
     */
    private const int MAX_MESSAGES = 500;

    public function testConnection(ImapConnectionConfig $config): void
    {
        $client = $this->connect($config);

        try {
            $client->getFolders(false);
        } catch (Throwable $e) {
            throw new ImapConnectionException($this->humanize($e), 0, $e);
        } finally {
            $this->disconnect($client);
        }
    }

    public function listFolders(ImapConnectionConfig $config): array
    {
        $client = $this->connect($config);

        try {
            $folders = [];

            foreach ($client->getFolders(false) as $folder) {
                $path = $folder->path;

                if (is_string($path) && '' !== $path) {
                    $folders[] = $path;
                }
            }

            return $folders;
        } catch (Throwable $e) {
            throw new ImapConnectionException($this->humanize($e), 0, $e);
        } finally {
            $this->disconnect($client);
        }
    }

    public function fetchHeaders(
        ImapConnectionConfig $config,
        string $folder,
        ?DateTimeImmutable $since = null,
        int $limit = 200,
    ): array {
        return $this->fetch(
            $config,
            $folder,
            $limit,
            'desc',
            static function (WhereQuery $query) use ($since): void {
                if (null !== $since) {
                    $query->whereSince($since);
                }
            },
        );
    }

    public function getUidValidity(ImapConnectionConfig $config, string $folder): int
    {
        $client = $this->connect($config);

        try {
            /** @var ImapProtocol $connection */
            $connection = $client->getConnection();
            $status = $connection->folderStatus($folder, ['UIDVALIDITY'])->data();

            if (!is_array($status) || !isset($status['uidvalidity'])) {
                throw new ImapFetchException(sprintf('El servidor no ha informado del UIDVALIDITY de la carpeta "%s".', $folder));
            }

            return (int) $status['uidvalidity'];
        } catch (ImapConnectionException|ImapFetchException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ImapConnectionException($this->humanize($e), 0, $e);
        } finally {
            $this->disconnect($client);
        }
    }

    public function fetchHeadersAfter(
        ImapConnectionConfig $config,
        string $folder,
        int $afterUid,
        int $limit = 200,
    ): array {
        return $this->fetch(
            $config,
            $folder,
            $limit,
            'asc',
            static fn (WhereQuery $query) => self::whereUidRange($query, sprintf('%d:*', max(1, $afterUid + 1))),
        );
    }

    public function fetchHeadersBefore(
        ImapConnectionConfig $config,
        string $folder,
        int $beforeUid,
        int $limit = 200,
    ): array {
        return $this->fetch(
            $config,
            $folder,
            $limit,
            'desc',
            static fn (WhereQuery $query) => self::whereUidRange($query, sprintf('1:%d', max(1, $beforeUid - 1))),
        );
    }

    /**
     * Lectura de cabeceras compartida por las tres variantes.
     *
     * Nunca descarga cuerpos ni marca los mensajes como leídos: `FT_PEEK` está
     * fijado en la configuración del cliente y `setFetchBody(false)` evita
     * pedir el contenido.
     *
     * @param callable(WhereQuery): void $constrain
     *
     * @return list<ImapMessageHeader>
     */
    private function fetch(
        ImapConnectionConfig $config,
        string $folder,
        int $limit,
        string $order,
        callable $constrain,
    ): array {
        $limit = max(1, min($limit, self::MAX_MESSAGES));
        $client = $this->connect($config);

        try {
            $imapFolder = $client->getFolder($folder);

            if (null === $imapFolder) {
                throw new ImapFetchException(sprintf('La carpeta "%s" no existe en el buzón.', $folder));
            }

            $query = $imapFolder->query()
                ->setFetchBody(false)
                ->setFetchFlags(false)
                ->setFetchOrder($order)
                ->leaveUnread()
                ->limit($limit);

            $constrain($query);

            $messages = $query->get();

            if (0 === $messages->count()) {
                return [];
            }

            $structures = $this->fetchBodyStructures($client, $messages);

            $headers = [];

            foreach ($messages as $message) {
                $headers[] = $this->toHeader($message, $structures[$message->getUid()] ?? null);
            }

            return $headers;
        } catch (ImapConnectionException|ImapFetchException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ImapConnectionException($this->humanize($e), 0, $e);
        } finally {
            $this->disconnect($client);
        }
    }

    /**
     * Añade un rango de UID a la búsqueda.
     *
     * `whereUid()` cita siempre el valor (`UID "100:*"`), que no es IMAP válido
     * para un rango. `CUSTOM` es la vía que ofrece la librería para pasar un
     * criterio literal sin que lo reescriba.
     */
    private static function whereUidRange(WhereQuery $query, string $range): void
    {
        $query->where('CUSTOM UID '.$range);
    }

    public function fetchBody(ImapConnectionConfig $config, string $folder, int $uid): ImapMessageBody
    {
        $client = $this->connect($config);

        try {
            $imapFolder = $client->getFolder($folder);

            if (null === $imapFolder) {
                throw new ImapFetchException(sprintf('La carpeta "%s" no existe en el buzón.', $folder));
            }

            $message = $imapFolder->query()
                ->whereUid($uid)
                ->setFetchBody(true)
                ->setFetchOrder('desc')
                ->leaveUnread()
                ->limit(1)
                ->get()
                ->first();

            if (false === $message || null === $message) {
                throw new ImapFetchException(sprintf('El mensaje con UID %d ya no está en la carpeta "%s".', $uid, $folder));
            }

            $message->peek();

            $names = [];
            $types = [];
            $contents = [];

            foreach ($message->getAttachments() as $attachment) {
                $name = $attachment->getName();
                $names[] = is_string($name) && '' !== $name ? $name : 'adjunto';
                $types[] = $attachment->getMimeType() ?? 'application/octet-stream';
                // El contenido ya viene decodificado con el mensaje. Se lee por
                // `getAttributes()` porque `getContent()` solo existe a través
                // del `__call` mágico de la librería.
                $contents[] = $this->attachmentContents($attachment);
            }

            return new ImapMessageBody(
                uid: $uid,
                textBody: $this->safeBody(static fn (): string => $message->getTextBody()),
                htmlBody: $this->safeBody(static fn (): string => $message->getHTMLBody()),
                attachmentNames: $names,
                attachmentTypes: $types,
                attachmentContents: $contents,
            );
        } catch (ImapConnectionException|ImapFetchException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ImapFetchException($this->humanize($e), 0, $e);
        } finally {
            $this->disconnect($client);
        }
    }

    /**
     * Contenido binario de un adjunto, ya decodificado por la librería.
     *
     * Devuelve cadena vacía si el adjunto no trae contenido: un adjunto
     * ilegible no debe impedir analizar el resto del mensaje.
     */
    private function attachmentContents(Attachment $attachment): string
    {
        $attributes = $attachment->getAttributes();
        $content = $attributes['content'] ?? null;

        return is_string($content) ? $content : '';
    }

    private function connect(ImapConnectionConfig $config): Client
    {
        $client = new Client($this->buildConfig($config));

        try {
            $client->connect();
        } catch (Throwable $e) {
            throw new ImapConnectionException($this->humanize($e), 0, $e);
        }

        return $client;
    }

    private function disconnect(Client $client): void
    {
        try {
            $client->disconnect();
        } catch (Throwable) {
            // Cerrar una conexión que ya está rota no es un error de negocio.
        }
    }

    private function buildConfig(ImapConnectionConfig $config): Config
    {
        return new Config([
            'date_format' => 'd-M-Y',
            'default' => 'default',
            'accounts' => [
                'default' => [
                    'host' => $config->host,
                    'port' => $config->port,
                    'protocol' => 'imap',
                    'encryption' => $this->encryptionValue($config->encryption),
                    'validate_cert' => true,
                    'username' => $config->username,
                    'password' => $config->password,
                    'authentication' => null,
                    'rfc' => 'RFC822',
                    'timeout' => $config->timeout,
                    'extensions' => [],
                ],
            ],
            'options' => [
                'delimiter' => '/',
                // FT_PEEK: leer nunca marca el mensaje como leído.
                'fetch' => IMAP::FT_PEEK,
                'sequence' => IMAP::ST_UID,
                'fetch_body' => false,
                'fetch_flags' => false,
                'soft_fail' => true,
                'rfc822' => true,
                'debug' => false,
                'uid_cache' => false,
                'message_key' => 'uid',
                'fetch_order' => 'desc',
                'dispositions' => ['attachment', 'inline'],
            ],
        ]);
    }

    private function encryptionValue(ImapEncryption $encryption): string
    {
        return match ($encryption) {
            ImapEncryption::SSL => 'ssl',
            ImapEncryption::STARTTLS => 'starttls',
            ImapEncryption::NONE => '',
        };
    }

    /**
     * Pide `BODYSTRUCTURE` en un solo comando para todos los mensajes.
     *
     * Es la forma barata de saber si un correo trae un PDF adjunto sin
     * descargar el cuerpo: el servidor devuelve la estructura MIME, no el
     * contenido. Sin esto, la señal «PDF adjunto» del `billingScore` solo
     * estaría disponible después de pagar la descarga, que es justo lo que el
     * pipeline quiere evitar (ARCHITECTURE.md §13.4).
     *
     * @param iterable<Message> $messages
     *
     * @return array<int, string>
     */
    private function fetchBodyStructures(Client $client, iterable $messages): array
    {
        $uids = [];

        foreach ($messages as $message) {
            $uids[] = $message->getUid();
        }

        if ([] === $uids) {
            return [];
        }

        try {
            /** @var ImapProtocol $connection */
            $connection = $client->getConnection();
            $response = $connection->fetch(['BODYSTRUCTURE'], $uids, null, IMAP::ST_UID);
            $data = $response->validatedData();
        } catch (Throwable) {
            // La estructura es una optimización: si el servidor no la sirve,
            // el pipeline sigue funcionando con el resto de señales.
            return [];
        }

        if (!is_array($data)) {
            return [];
        }

        $structures = [];

        foreach ($data as $uid => $structure) {
            if (is_string($structure) && '' !== $structure) {
                $structures[(int) $uid] = $structure;
            }
        }

        return $structures;
    }

    private function toHeader(Message $message, ?string $rawStructure): ImapMessageHeader
    {
        $attachments = $this->parseAttachments($rawStructure);

        return new ImapMessageHeader(
            uid: $message->getUid(),
            messageId: $this->attributeString($message->getMessageId()),
            fromAddress: $this->firstAddress($message->getFrom()),
            fromName: $this->firstAddressName($message->getFrom()),
            replyTo: $this->firstAddress($message->getReplyTo()),
            toAddresses: $this->addressList($message->getTo()),
            subject: $this->attributeString($message->getSubject()) ?? '',
            receivedAt: $this->attributeDate($message->getDate()),
            sizeBytes: $this->safeSize($message),
            contentType: $this->attributeString($message->getHeader()?->get('content_type')),
            attachmentNames: $attachments['names'],
            attachmentTypes: $attachments['types'],
        );
    }

    /**
     * Extrae nombres y tipos de adjunto de una respuesta `BODYSTRUCTURE`.
     *
     * El formato es una lista anidada de paréntesis; no se intenta interpretarla
     * entera, solo localizar los pares `"FILENAME" "x"` / `"NAME" "x"` y los
     * tipos MIME de primer nivel. Es suficiente para decidir si un correo trae
     * una factura y para no volver a descargarlo.
     *
     * @return array{names: list<string>, types: list<string>}
     */
    private function parseAttachments(?string $rawStructure): array
    {
        if (null === $rawStructure || '' === $rawStructure) {
            return ['names' => [], 'types' => []];
        }

        $names = [];

        if (preg_match_all('/"(?:FILENAME|NAME)"\s+"([^"]*)"/i', $rawStructure, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $name = trim($name);

                if ('' !== $name && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        $types = [];

        if (preg_match_all('/"([A-Z]+)"\s+"([A-Z0-9.+\-]+)"/', $rawStructure, $matches) > 0) {
            foreach ($matches[2] as $index => $subtype) {
                $type = strtolower($matches[1][$index].'/'.$subtype);

                if (!in_array($type, $types, true)) {
                    $types[] = $type;
                }
            }
        }

        return ['names' => $names, 'types' => $types];
    }

    private function attributeString(mixed $attribute): ?string
    {
        if ($attribute instanceof Attribute) {
            $value = $attribute->first();

            if ($value instanceof Address) {
                return $value->mail;
            }

            return is_string($value) ? trim($value) : null;
        }

        return is_string($attribute) ? trim($attribute) : null;
    }

    private function attributeDate(mixed $attribute): ?DateTimeImmutable
    {
        if (!$attribute instanceof Attribute) {
            return null;
        }

        try {
            return DateTimeImmutable::createFromInterface($attribute->toDate());
        } catch (Throwable) {
            return null;
        }
    }

    private function firstAddress(mixed $attribute): ?string
    {
        if (!$attribute instanceof Attribute) {
            return null;
        }

        $first = $attribute->first();

        if ($first instanceof Address) {
            return '' === $first->mail ? null : $first->mail;
        }

        return is_string($first) && '' !== $first ? $first : null;
    }

    private function firstAddressName(mixed $attribute): ?string
    {
        if (!$attribute instanceof Attribute) {
            return null;
        }

        $first = $attribute->first();

        if ($first instanceof Address) {
            return '' === $first->personal ? null : $first->personal;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function addressList(mixed $attribute): array
    {
        if (!$attribute instanceof Attribute) {
            return [];
        }

        $addresses = [];

        foreach ($attribute->all() as $address) {
            if ($address instanceof Address && '' !== $address->mail) {
                $addresses[] = $address->mail;
            } elseif (is_string($address) && '' !== $address) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    private function safeSize(Message $message): int
    {
        try {
            return $message->getSize();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param callable(): string $reader
     */
    private function safeBody(callable $reader): string
    {
        try {
            return $reader();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Traduce los fallos de la librería a algo que un usuario pueda leer.
     *
     * Nunca se propaga el mensaje original: puede contener el comando IMAP con
     * la contraseña (SECURITY.md §3).
     */
    private function humanize(Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        return match (true) {
            str_contains($message, 'auth') || str_contains($message, 'login') || str_contains($message, 'credential') => 'El servidor ha rechazado el usuario o la contraseña. Si tu proveedor exige verificación en dos pasos, necesitas una contraseña de aplicación.',
            str_contains($message, 'connection refused') || str_contains($message, 'connection failed') => 'No se ha podido conectar con el servidor. Comprueba el nombre del servidor y el puerto.',
            str_contains($message, 'certificate') || str_contains($message, 'ssl') || str_contains($message, 'tls') => 'El certificado del servidor no es válido o el cifrado no coincide con el puerto.',
            str_contains($message, 'timeout') || str_contains($message, 'timed out') => 'El servidor ha tardado demasiado en responder.',
            str_contains($message, 'no such host') || str_contains($message, 'getaddrinfo') => 'No se ha podido resolver el nombre del servidor de correo.',
            default => 'No se ha podido completar la operación con el servidor de correo.',
        };
    }
}
