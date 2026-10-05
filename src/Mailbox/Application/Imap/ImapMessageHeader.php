<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Imap;

use App\Mailbox\Domain\Entity\EmailMessage;
use DateTimeImmutable;

use function str_contains;
use function str_starts_with;
use function strrpos;
use function strtolower;
use function substr;

/**
 * Metadatos de un mensaje (nivel 1 del pipeline, ARCHITECTURE.md §13.4).
 *
 * Se obtienen sin descargar el cuerpo: es lo que permite descartar la mayor
 * parte del buzón sin coste de red ni de IA.
 */
final readonly class ImapMessageHeader
{
    /**
     * @param list<string> $toAddresses
     * @param list<string> $attachmentNames
     * @param list<string> $attachmentTypes
     */
    public function __construct(
        public ?int $uid,
        public ?string $messageId,
        public ?string $fromAddress,
        public ?string $fromName,
        public ?string $replyTo,
        public array $toAddresses,
        public string $subject,
        public ?DateTimeImmutable $receivedAt,
        public int $sizeBytes,
        public ?string $contentType,
        public array $attachmentNames,
        public array $attachmentTypes,
    ) {
    }

    public function hasAttachments(): bool
    {
        return [] !== $this->attachmentNames;
    }

    /**
     * Reconstruye la cabecera a partir de un mensaje ya almacenado.
     *
     * El pipeline puntúa y extrae a partir de `ImapMessageHeader`, pero el
     * procesamiento ocurre en un mensaje de Messenger, es decir, en otra
     * petición: lo único que sobrevive es la fila de `email_message`. Esta
     * fábrica evita que el orquestador tenga que conocer los dos modelos.
     */
    public static function fromStoredMessage(EmailMessage $message): self
    {
        return new self(
            uid: $message->getUid(),
            messageId: $message->getMessageId(),
            fromAddress: $message->getFromAddress(),
            fromName: $message->getFromName(),
            replyTo: $message->getReplyTo(),
            toAddresses: $message->getToAddresses(),
            subject: $message->getSubject() ?? '',
            receivedAt: $message->getReceivedAt(),
            sizeBytes: $message->getSizeBytes() ?? 0,
            contentType: $message->getContentType(),
            attachmentNames: $message->getAttachmentNames(),
            attachmentTypes: $message->getAttachmentTypes(),
        );
    }

    /**
     * Indicio de adjunto a partir de la cabecera `Content-Type`.
     *
     * En el pase de metadatos no se descarga el cuerpo, así que los nombres de
     * adjunto solo se conocen si el servidor ha servido `BODYSTRUCTURE`. Cuando
     * no es el caso, `multipart/mixed` es la señal barata de que el correo
     * probablemente lleva algo pegado (ARCHITECTURE.md §13.4).
     */
    public function mayHaveAttachments(): bool
    {
        if ($this->hasAttachments()) {
            return true;
        }

        $contentType = strtolower($this->contentType ?? '');

        return str_starts_with($contentType, 'multipart/mixed')
            || str_starts_with($contentType, 'multipart/related');
    }

    /**
     * Dominio del remitente, en minúsculas. Es la señal más barata y más
     * fiable para reconocer proveedores (ARCHITECTURE.md §13.6).
     */
    public function senderDomain(): ?string
    {
        if (null === $this->fromAddress || !str_contains($this->fromAddress, '@')) {
            return null;
        }

        $domain = mb_strtolower(substr($this->fromAddress, (int) strrpos($this->fromAddress, '@') + 1));

        return '' === $domain ? null : $domain;
    }

    /**
     * Parte local del remitente (`facturas@ovh.com` → `facturas`).
     */
    public function senderLocalPart(): ?string
    {
        if (null === $this->fromAddress || !str_contains($this->fromAddress, '@')) {
            return null;
        }

        return mb_strtolower(substr($this->fromAddress, 0, (int) strrpos($this->fromAddress, '@')));
    }
}
