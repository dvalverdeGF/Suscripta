<?php

declare(strict_types=1);

namespace App\Tests\Support\Documents;

use App\Documents\Domain\Exception\DocumentStorageException;
use App\Documents\Domain\Service\DocumentStorageInterface;

use function array_key_exists;
use function sprintf;

/**
 * Almacenamiento en memoria. Guarda las claves escritas para poder comprobar
 * cómo se reparten en directorios y que el binario se escribe **antes** de que
 * la entidad se persista.
 */
final class InMemoryDocumentStorage implements DocumentStorageInterface
{
    /** @var array<string, string> */
    private array $files = [];

    /** @var list<string> */
    public array $written = [];

    /** @var list<string> */
    public array $deleted = [];

    public function driver(): string
    {
        return 'memory';
    }

    public function write(string $key, string $contents): void
    {
        $this->files[$key] = $contents;
        $this->written[] = $key;
    }

    public function read(string $key): string
    {
        if (!array_key_exists($key, $this->files)) {
            throw new DocumentStorageException(sprintf('El documento "%s" no existe.', $key));
        }

        return $this->files[$key];
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->files);
    }

    public function delete(string $key): void
    {
        unset($this->files[$key]);
        $this->deleted[] = $key;
    }
}
