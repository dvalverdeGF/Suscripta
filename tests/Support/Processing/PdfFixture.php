<?php

declare(strict_types=1);

namespace App\Tests\Support\Processing;

use function array_filter;
use function array_values;
use function count;
use function implode;
use function sprintf;
use function str_replace;
use function strlen;

/**
 * Genera PDFs reales y mínimos para las pruebas.
 *
 * Hace falta un PDF de verdad, no una cadena que empiece por `%PDF-`, porque
 * `smalot/pdfparser` valida la tabla `xref` y los desplazamientos. Un PDF
 * inventado falla con "Unable to find startxref" y la prueba no probaría nada.
 */
final class PdfFixture
{
    /**
     * PDF de una página con una única línea de texto.
     */
    public static function withText(string $text): string
    {
        return self::withLines([$text]);
    }

    /**
     * PDF de una página con varias líneas de texto.
     *
     * Hace falta para las pruebas de OCR: una línea larga se sale del ancho de
     * la página y el reconocimiento la corta a media frase.
     *
     * @param list<string> $lines
     */
    public static function withLines(array $lines): string
    {
        $objects = [
            '<</Type/Catalog/Pages 2 0 R>>',
            '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            '<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 200]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>',
            self::contentStream($lines),
            '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
        ];

        $document = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $body) {
            $offsets[] = strlen($document);
            $document .= sprintf("%d 0 obj\n%s\nendobj\n", $index + 1, $body);
        }

        $xrefPosition = strlen($document);
        $size = count($objects) + 1;

        $document .= sprintf("xref\n0 %d\n", $size);
        $document .= "0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $document .= sprintf("%010d 00000 n \n", $offset);
        }

        $document .= sprintf("trailer\n<</Size %d/Root 1 0 R>>\nstartxref\n%d\n%%%%EOF\n", $size, $xrefPosition);

        return $document;
    }

    /**
     * PDF sin capa de texto: solo un rectángulo. Es lo que devuelve un escáner
     * antes de pasar por OCR.
     */
    public static function withoutText(): string
    {
        return self::withText('');
    }

    /**
     * @param list<string> $lines
     */
    private static function contentStream(array $lines): string
    {
        $lines = array_values(array_filter($lines, static fn (string $line): bool => '' !== $line));

        if ([] === $lines) {
            $stream = '0 0 1 rg 20 20 100 50 re f';
        } else {
            $parts = [];

            foreach ($lines as $index => $line) {
                // La primera línea se coloca con `Td`; las siguientes bajan 20
                // puntos con `T*` para no repetir la matriz de texto.
                $parts[] = 0 === $index
                    ? sprintf('20 160 Td (%s) Tj', self::escape($line))
                    : sprintf('0 -20 Td (%s) Tj', self::escape($line));
            }

            $stream = sprintf('BT /F1 12 Tf %s ET', implode(' ', $parts));
        }

        // Las comillas dobles son obligatorias: con comillas simples `\n` sería
        // una barra invertida literal y `smalot/pdfparser` no encontraría el
        // salto de línea que separa `stream` de su contenido.
        return sprintf("<</Length %d>>stream\n%s\nendstream", strlen($stream), $stream);
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
