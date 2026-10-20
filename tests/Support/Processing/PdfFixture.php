<?php

declare(strict_types=1);

namespace App\Tests\Support\Processing;

use function array_filter;
use function array_values;
use function bin2hex;
use function count;
use function file_get_contents;
use function file_put_contents;
use function getimagesize;
use function glob;
use function implode;
use function is_dir;
use function is_file;
use function is_resource;
use function mkdir;
use function proc_close;
use function proc_open;
use function random_bytes;
use function rmdir;

use RuntimeException;

use function sprintf;
use function str_replace;
use function strlen;

use Symfony\Component\Process\ExecutableFinder;

use function sys_get_temp_dir;
use function unlink;

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
     * PDF **escaneado**: una imagen rasterizada y sin capa de texto.
     *
     * Es el único tipo de PDF que obliga a pasar por OCR. Un PDF generado con
     * `withLines()` lleva texto embebido, así que `PdfTextExtractor` lo lee sin
     * necesidad de reconocer nada y el OCR nunca llega a ejecutarse: la
     * prueba de OCR no probaría el OCR.
     *
     * El camino real es otro: un escáner (o un móvil) produce una imagen que
     * alguien envuelve en un PDF. Aquí se replica ese resultado en dos pasos:
     *
     * 1. Se genera un PDF con texto y se rasteriza con `pdftoppm` a JPEG.
     * 2. El JPEG se embebe en un PDF nuevo como `XObject` de imagen
     *    (`DCTDecode`), sin ninguna capa de texto.
     *
     * `MediaBox` y la matriz `cm` usan las dimensiones **reales en píxeles** de
     * la imagen: si no coinciden, `pdftoppm` falla al rasterizar el resultado
     * y el OCR no devuelve nada.
     *
     * @param list<string> $lines
     */
    public static function scanned(array $lines): string
    {
        $directory = self::temporaryDirectory();

        if (null === $directory) {
            throw new RuntimeException('No se pudo crear el directorio temporal para la fixture escaneada.');
        }

        try {
            $source = $directory.'/origen.pdf';
            file_put_contents($source, self::withLines($lines));

            $rasterizer = new ExecutableFinder()->find('pdftoppm');

            if (null === $rasterizer) {
                throw new RuntimeException('Se necesita `pdftoppm` (poppler-utils) para generar la fixture escaneada.');
            }

            $result = self::run([
                $rasterizer,
                '-r', '300',
                '-jpeg',
                '-jpegopt', 'quality=95',
                '-l', '1',
                $source,
                $directory.'/pagina',
            ]);

            if (0 !== $result) {
                throw new RuntimeException('`pdftoppm` no pudo rasterizar el PDF de la fixture escaneada.');
            }

            $image = $directory.'/pagina-1.jpg';

            if (!is_file($image)) {
                throw new RuntimeException('`pdftoppm` no generó la imagen esperada para la fixture escaneada.');
            }

            $size = getimagesize($image);

            if (false === $size) {
                throw new RuntimeException('No se pudo leer el tamaño de la imagen rasterizada.');
            }

            return self::imagePdf((string) file_get_contents($image), $size[0], $size[1]);
        } finally {
            self::removeDirectory($directory);
        }
    }

    /**
     * Envuelve una imagen JPEG en un PDF de una página, sin capa de texto.
     */
    private static function imagePdf(string $jpeg, int $width, int $height): string
    {
        $content = sprintf('q %d 0 0 %d 0 0 cm /Im0 Do Q', $width, $height);

        $objects = [
            '<</Type/Catalog/Pages 2 0 R>>',
            '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            sprintf(
                '<</Type/Page/Parent 2 0 R/MediaBox[0 0 %d %d]/Contents 4 0 R/Resources<</XObject<</Im0 5 0 R>>>>/ProcSet[/PDF/ImageC]>>',
                $width,
                $height,
            ),
            sprintf("<</Length %d>>stream\n%s\nendstream", strlen($content), $content),
            sprintf(
                "<</Type/XObject/Subtype/Image/Width %d/Height %d/ColorSpace/DeviceRGB/BitsPerComponent 8/Filter/DCTDecode/Length %d>>stream\n",
                $width,
                $height,
                strlen($jpeg),
            ).$jpeg."\nendstream",
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

    /**
     * Ejecuta un binario sin shell y devuelve su código de salida.
     *
     * @param list<string> $command
     */
    private static function run(array $command): int
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return 1;
        }

        return proc_close($process);
    }

    private static function temporaryDirectory(): ?string
    {
        $path = sprintf('%s/suscripta-fixture-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));

        return is_dir($path) || mkdir($path, 0o700, true) ? $path : null;
    }

    private static function removeDirectory(string $directory): void
    {
        $files = glob($directory.'/*');

        if (false !== $files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }

        rmdir($directory);
    }
}
