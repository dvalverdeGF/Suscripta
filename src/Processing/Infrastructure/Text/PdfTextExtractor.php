<?php

declare(strict_types=1);

namespace App\Processing\Infrastructure\Text;

use App\Processing\Domain\Text\DocumentTextExtractorInterface;
use Smalot\PdfParser\Parser;
use Throwable;

use function in_array;
use function mb_strtolower;
use function pathinfo;
use function str_ends_with;
use function trim;

use const PATHINFO_EXTENSION;

/**
 * Capa de texto de un PDF (ARCHITECTURE.md §13.6).
 *
 * La inmensa mayoría de facturas de software son PDFs generados por el propio
 * proveedor, con texto seleccionable. Leerlos aquí cuesta microsegundos y evita
 * tanto el OCR como la IA.
 *
 * Un PDF escaneado devuelve cadena vacía —no `null`—, que es exactamente la
 * señal que el pipeline necesita para decidir que toca OCR.
 */
final readonly class PdfTextExtractor implements DocumentTextExtractorInterface
{
    public const string NAME = 'pdf';

    private const array MIME_TYPES = ['application/pdf', 'application/x-pdf'];

    public function priority(): int
    {
        return 100;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(string $mimeType, string $filename): bool
    {
        if (in_array(mb_strtolower($mimeType), self::MIME_TYPES, true)) {
            return true;
        }

        // Algunos proveedores envían el PDF como octet-stream; la extensión es
        // entonces la única pista fiable.
        return 'pdf' === mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    public function extract(string $contents, string $mimeType, string $filename): ?string
    {
        if (!$this->supports($mimeType, $filename)) {
            return null;
        }

        try {
            $text = (new Parser())->parseContent($contents)->getText();
        } catch (Throwable) {
            // Un PDF corrupto o cifrado no debe tumbar el lote: se comporta
            // como un PDF sin texto y el pipeline decide qué hacer.
            return '';
        }

        return trim($text);
    }
}
