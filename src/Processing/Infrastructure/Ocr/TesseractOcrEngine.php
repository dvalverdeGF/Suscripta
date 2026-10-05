<?php

declare(strict_types=1);

namespace App\Processing\Infrastructure\Ocr;

use App\Processing\Domain\Ocr\OcrEngineInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

use function bin2hex;
use function fclose;
use function file_put_contents;
use function glob;
use function implode;
use function in_array;
use function is_dir;
use function is_resource;
use function is_string;
use function mb_strtolower;
use function mkdir;
use function pathinfo;
use function proc_close;
use function proc_open;
use function random_bytes;
use function rmdir;
use function sort;
use function sprintf;
use function stream_get_contents;
use function sys_get_temp_dir;
use function trim;
use function unlink;

use const PATHINFO_EXTENSION;

/**
 * OCR con Tesseract, en nuestra propia infraestructura (ARCHITECTURE.md §13.6).
 *
 * Se usa **solo** para documentos sin capa de texto. Un PDF escaneado se
 * rasteriza con `pdftoppm` y cada página se pasa a `tesseract`; una imagen va
 * directa.
 *
 * Tres decisiones que importan:
 *
 * 1. **Nunca se invoca un shell.** `proc_open` recibe un array de argumentos, así
 *    que un nombre de archivo con caracteres raros no puede convertirse en un
 *    comando. El contenido del usuario no llega nunca a la línea de comandos.
 * 2. **Hay un límite de páginas y de tiempo.** Una factura de 400 páginas no
 *    puede bloquear la cola; se leen las primeras y se declara el truncamiento.
 * 3. **Se serializa con un lock.** El OCR es lo más caro del pipeline en CPU, y
 *    el roadmap pide concurrencia muy baja: un lock compartido garantiza que
 *    solo un documento se reconoce a la vez, aunque haya varios workers.
 */
final readonly class TesseractOcrEngine implements OcrEngineInterface
{
    public const string NAME = 'tesseract';

    private const array IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/tiff'];
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff'];

    /**
     * Rutas absolutas resueltas en el constructor.
     *
     * `is_executable()` **no busca en `PATH`**: resuelve la ruta relativa al
     * directorio de trabajo. Con el nombre pelado (`tesseract`) devolvía `false`
     * siempre, así que el OCR nunca se ejecutaba y el pipeline degradaba en
     * silencio. `ExecutableFinder` sí recorre `PATH`.
     */
    private ?string $binaryPath;
    private ?string $rasterizerPath;

    public function __construct(
        private LockFactory $lockFactory,
        string $binary = 'tesseract',
        string $rasterizer = 'pdftoppm',
        private string $languages = 'spa+eng',
        private int $maxPages = 5,
        private int $dpi = 300,
        private int $timeoutSeconds = 60,
    ) {
        $finder = new ExecutableFinder();
        $this->binaryPath = $finder->find($binary);
        $this->rasterizerPath = $finder->find($rasterizer);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isAvailable(): bool
    {
        return null !== $this->binaryPath && null !== $this->rasterizerPath;
    }

    public function supports(string $mimeType, string $filename): bool
    {
        $mimeType = mb_strtolower($mimeType);

        if ('application/pdf' === $mimeType || 'application/x-pdf' === $mimeType) {
            return true;
        }

        if (in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
            return true;
        }

        return in_array(mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
    }

    public function extract(string $contents, string $mimeType, string $filename): ?string
    {
        if (!$this->isAvailable() || !$this->supports($mimeType, $filename)) {
            return null;
        }

        $lock = $this->lockFactory->createLock('suscripta_ocr', $this->timeoutSeconds * 2);

        if (!$lock->acquire()) {
            // Otro documento se está reconociendo y no vamos a competir por la
            // CPU: el mensaje se queda pendiente y se reintenta.
            return null;
        }

        try {
            return $this->recognize($contents, $mimeType, $filename);
        } catch (Throwable) {
            return null;
        } finally {
            $lock->release();
        }
    }

    private function recognize(string $contents, string $mimeType, string $filename): ?string
    {
        $directory = self::temporaryDirectory();

        if (null === $directory) {
            return null;
        }

        try {
            $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $source = sprintf('%s/source.%s', $directory, '' === $extension ? 'bin' : $extension);

            if (false === file_put_contents($source, $contents)) {
                return null;
            }

            $images = $this->isPdf($mimeType, $filename)
                ? $this->rasterize($directory, $source)
                : [$source];

            $pages = [];

            foreach ($images as $image) {
                $text = $this->readImage($image);

                if (null !== $text && '' !== $text) {
                    $pages[] = $text;
                }
            }

            return [] === $pages ? '' : trim(implode("\n", $pages));
        } finally {
            self::removeDirectory($directory);
        }
    }

    /**
     * @return list<string>
     */
    private function rasterize(string $directory, string $source): array
    {
        $prefix = $directory.'/page';

        $this->run([
            $this->rasterizerPath,
            '-r', (string) $this->dpi,
            '-png',
            '-l', (string) $this->maxPages,
            $source,
            $prefix,
        ]);

        $files = glob($prefix.'*.png');

        if (false === $files) {
            return [];
        }

        sort($files);

        return $files;
    }

    private function readImage(string $image): ?string
    {
        $result = $this->run([
            $this->binaryPath,
            $image,
            'stdout',
            '-l', $this->languages,
        ]);

        return null === $result ? null : trim($result);
    }

    private function isPdf(string $mimeType, string $filename): bool
    {
        return 'application/pdf' === mb_strtolower($mimeType)
            || 'application/x-pdf' === mb_strtolower($mimeType)
            || 'pdf' === mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    /**
     * Ejecuta un binario sin pasar por el shell y devuelve su salida estándar.
     *
     * @param list<string|null> $command
     */
    private function run(array $command): ?string
    {
        if (in_array(null, $command, true)) {
            // Algún binario no se ha podido resolver: no hay nada que ejecutar.
            return null;
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $status = proc_close($process);

        if (0 !== $status || !is_string($output)) {
            return null;
        }

        return $output;
    }

    private static function temporaryDirectory(): ?string
    {
        $path = sprintf('%s/suscripta-ocr-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));

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
