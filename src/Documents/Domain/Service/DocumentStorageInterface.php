<?php

declare(strict_types=1);

namespace App\Documents\Domain\Service;

use App\Documents\Domain\Exception\DocumentStorageException;

/**
 * Dónde viven los binarios (ARCHITECTURE.md §9).
 *
 * El dominio solo conoce esta interfaz: hoy escribe en disco local, mañana
 * puede escribir en S3 o en cualquier almacenamiento compatible sin que nada
 * más cambie. La clave la decide el llamante (`storageKey`), no el adaptador,
 * para que el documento sea reproducible y auditable.
 */
interface DocumentStorageInterface
{
    /**
     * Nombre del adaptador. Se persiste en `Document.storageDriver` para poder
     * migrar de uno a otro sin adivinar dónde está cada fichero.
     */
    public function driver(): string;

    /**
     * @throws DocumentStorageException si no se puede escribir
     */
    public function write(string $key, string $contents): void;

    /**
     * @throws DocumentStorageException si no existe o no se puede leer
     */
    public function read(string $key): string;

    public function exists(string $key): bool;

    /**
     * Borrado físico. Es idempotente: borrar algo que ya no está no es un error.
     */
    public function delete(string $key): void;
}
