<?php

declare(strict_types=1);

namespace App\Shared\Application\Privacy;

use DateTimeImmutable;

/**
 * Purga los datos que han superado su política de retención.
 *
 * Las políticas son **por categoría de dato** (correo, documentos, datos
 * estructurados, metadatos de procesamiento) y no por módulo: el correo se
 * purga antes que los servicios porque el correo es evidencia temporal y los
 * servicios son el producto (SECURITY.md §6).
 *
 * Cada implementación declara a qué categoría pertenece, de modo que el
 * informe de la purga dice **qué se ha borrado y por qué**.
 */
interface RetentionPurgerInterface
{
    /**
     * Categoría de dato que este purificador limpia.
     */
    public function category(): string;

    /**
     * Borra los datos caducados.
     *
     * @return int número de elementos eliminados
     */
    public function purge(DateTimeImmutable $now): int;
}
