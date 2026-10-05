<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Imap;

/**
 * Cuerpo de un mensaje, descargado de forma perezosa (D-38).
 *
 * Solo se pide cuando el mensaje ha superado los filtros deterministas. Nunca
 * se persiste: se procesa y se descarta (D-10).
 */
final readonly class ImapMessageBody
{
    /**
     * @param list<string> $attachmentNames
     * @param list<string> $attachmentTypes
     */
    public function __construct(
        public int $uid,
        public string $textBody,
        public string $htmlBody,
        public array $attachmentNames = [],
        public array $attachmentTypes = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return '' === trim($this->textBody) && '' === trim($this->htmlBody);
    }
}
