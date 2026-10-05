<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Imap;

use function array_keys;
use function is_string;
use function trim;

/**
 * Cuerpo de un mensaje, descargado de forma perezosa (D-38).
 *
 * Solo se pide cuando el mensaje ha superado los filtros deterministas. Nunca
 * se persiste: se procesa y se descarta (D-10).
 *
 * Los adjuntos viajan aquí porque el mensaje ya se ha descargado entero: no
 * pedirlos costaría exactamente lo mismo y obligaría a una segunda conexión.
 * Su contenido se usa para extraer texto (nivel 3) y se descarta igual que el
 * cuerpo.
 */
final readonly class ImapMessageBody
{
    /**
     * @param list<string> $attachmentNames
     * @param list<string> $attachmentTypes
     * @param list<string> $attachmentContents contenido binario de cada adjunto, en el mismo orden
     */
    public function __construct(
        public int $uid,
        public string $textBody,
        public string $htmlBody,
        public array $attachmentNames = [],
        public array $attachmentTypes = [],
        public array $attachmentContents = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return '' === trim($this->textBody) && '' === trim($this->htmlBody);
    }

    /**
     * Adjunto por posición, con su nombre, su tipo y su contenido.
     *
     * Devuelve `null` si el índice no existe o si el contenido no se pudo
     * descargar: un adjunto ilegible no debe romper el análisis del mensaje.
     *
     * @return array{name: string, type: string, contents: string}|null
     */
    public function attachment(int $index): ?array
    {
        $contents = $this->attachmentContents[$index] ?? null;

        if (!is_string($contents) || '' === $contents) {
            return null;
        }

        return [
            'name' => $this->attachmentNames[$index] ?? 'adjunto',
            'type' => $this->attachmentTypes[$index] ?? 'application/octet-stream',
            'contents' => $contents,
        ];
    }

    /**
     * @return list<array{name: string, type: string, contents: string}>
     */
    public function attachments(): array
    {
        $attachments = [];

        foreach (array_keys($this->attachmentNames) as $index) {
            $attachment = $this->attachment($index);

            if (null !== $attachment) {
                $attachments[] = $attachment;
            }
        }

        return $attachments;
    }
}
