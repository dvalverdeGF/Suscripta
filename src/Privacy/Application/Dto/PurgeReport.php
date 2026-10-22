<?php

declare(strict_types=1);

namespace App\Privacy\Application\Dto;

use function array_sum;

/**
 * Lo que ha purgado la política de retención, por categoría de dato.
 *
 * La categoría es lo que importa: el correo se purga antes que los servicios
 * porque el correo es evidencia temporal y los servicios son el producto
 * (SECURITY.md §6).
 */
final readonly class PurgeReport
{
    /**
     * @param array<string, int> $removedByCategory
     */
    public function __construct(public array $removedByCategory = [])
    {
    }

    public function total(): int
    {
        return array_sum($this->removedByCategory);
    }

    public function isEmpty(): bool
    {
        return 0 === $this->total();
    }
}
