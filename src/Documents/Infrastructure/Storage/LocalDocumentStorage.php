<?php

declare(strict_types=1);

namespace App\Documents\Infrastructure\Storage;

use App\Documents\Domain\Exception\DocumentStorageException;
use App\Documents\Domain\Service\DocumentStorageInterface;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function sprintf;
use function str_contains;
use function unlink;

/**
 * Almacenamiento en disco local (ARCHITECTURE.md §9).
 *
 * Los ficheros **no** se sirven por URL directa: viven fuera del directorio
 * público y solo se leen a través de un controlador que comprueba permisos. Es
 * la diferencia entre "el documento es privado" y "el documento está en una
 * carpeta con un nombre difícil de adivinar".
 */
final readonly class LocalDocumentStorage implements DocumentStorageInterface
{
    public function __construct(private string $directory)
    {
    }

    public function driver(): string
    {
        return 'local';
    }

    public function write(string $key, string $contents): void
    {
        $path = $this->path($key);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            throw new DocumentStorageException(sprintf('No se ha podido crear el directorio "%s".', $directory));
        }

        if (false === file_put_contents($path, $contents)) {
            throw new DocumentStorageException(sprintf('No se ha podido escribir el documento "%s".', $key));
        }
    }

    public function read(string $key): string
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            throw new DocumentStorageException(sprintf('El documento "%s" no existe.', $key));
        }

        $contents = file_get_contents($path);

        if (false === $contents) {
            throw new DocumentStorageException(sprintf('No se ha podido leer el documento "%s".', $key));
        }

        return $contents;
    }

    public function exists(string $key): bool
    {
        return file_exists($this->path($key));
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Resuelve la clave dentro del directorio raíz y **rechaza cualquier intento
     * de salir de él**. La clave la genera el sistema, pero un documento que
     * llegó por correo trae un nombre de fichero que controla un tercero: sin
     * esta comprobación, un adjunto llamado `../../.env` escribiría fuera del
     * almacenamiento.
     */
    private function path(string $key): string
    {
        if ('' === $key || str_contains($key, '..') || str_contains($key, "\0")) {
            throw new DocumentStorageException(sprintf('Clave de almacenamiento no válida: "%s".', $key));
        }

        return sprintf('%s/%s', $this->directory, $key);
    }
}
