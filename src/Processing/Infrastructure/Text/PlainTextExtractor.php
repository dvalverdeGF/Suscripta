<?php

declare(strict_types=1);

namespace App\Processing\Infrastructure\Text;

use App\Processing\Domain\Text\DocumentTextExtractorInterface;

use function html_entity_decode;
use function in_array;
use function mb_check_encoding;
use function mb_convert_encoding;
use function mb_strtolower;
use function pathinfo;
use function preg_replace;
use function str_contains;
use function str_starts_with;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const PATHINFO_EXTENSION;

/**
 * Documentos que ya son texto (ARCHITECTURE.md §13.6).
 *
 * Algunos proveedores adjuntan la factura como `.txt`, `.csv` o `.xml`, y
 * bastantes envían el detalle en el propio cuerpo del correo. En esos casos no
 * hay nada que interpretar: se limpia el marcado y se entrega.
 */
final readonly class PlainTextExtractor implements DocumentTextExtractorInterface
{
    public const string NAME = 'plain';

    private const array MIME_TYPES = [
        'text/plain',
        'text/csv',
        'text/html',
        'text/xml',
        'application/xml',
        'application/json',
    ];

    private const array EXTENSIONS = ['txt', 'csv', 'xml', 'json', 'html', 'htm'];

    public function priority(): int
    {
        return 0;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(string $mimeType, string $filename): bool
    {
        $mimeType = mb_strtolower($mimeType);

        if (in_array($mimeType, self::MIME_TYPES, true) || str_starts_with($mimeType, 'text/')) {
            return true;
        }

        $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, self::EXTENSIONS, true);
    }

    public function extract(string $contents, string $mimeType, string $filename): ?string
    {
        if (!$this->supports($mimeType, $filename)) {
            return null;
        }

        if (!mb_check_encoding($contents, 'UTF-8')) {
            // Un adjunto en Latin-1 no debe producir texto con bytes inválidos
            // que rompan la extracción posterior.
            $contents = mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-1');
        }

        if (str_contains(mb_strtolower($mimeType), 'html') || str_contains(mb_strtolower($filename), '.htm')) {
            $contents = self::stripMarkup($contents);
        }

        return trim($contents);
    }

    /**
     * Quita etiquetas y colapsa espacios. No pretende ser un parser de HTML:
     * solo dejar texto legible para las expresiones regulares del extractor.
     */
    private static function stripMarkup(string $html): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = preg_replace('/<br\s*\/?>|<\/p>|<\/div>|<\/tr>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<[^>]+>/', ' ', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    }
}
