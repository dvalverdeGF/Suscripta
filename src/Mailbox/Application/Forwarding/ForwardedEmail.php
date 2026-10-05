<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Forwarding;

use DateTimeImmutable;

/**
 * Un correo que llega reenviado a la dirección de ingesta (D-21).
 *
 * Es un DTO de frontera: lo construye el controlador a partir de la petición
 * HTTP y lo consume `IngestForwardedEmail`. El dominio no sabe nada de HTTP.
 */
final readonly class ForwardedEmail
{
    /**
     * @param list<string> $toAddresses
     * @param list<string> $attachmentNames
     * @param list<string> $attachmentTypes
     */
    public function __construct(
        public string $toAddress,
        public string $fromAddress,
        public ?string $fromName,
        public string $subject,
        public ?string $messageId,
        public ?DateTimeImmutable $receivedAt,
        public string $textBody,
        public string $htmlBody = '',
        public array $toAddresses = [],
        public array $attachmentNames = [],
        public array $attachmentTypes = [],
        public int $sizeBytes = 0,
    ) {
    }

    /**
     * Cuerpo útil para el pipeline: el texto si lo hay, y si no el HTML.
     */
    public function body(): string
    {
        return '' !== trim($this->textBody) ? $this->textBody : $this->htmlBody;
    }
}
