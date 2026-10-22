<?php

declare(strict_types=1);

namespace App\Privacy\Application\Dto;

use function array_sum;

/**
 * Lo que se ha borrado de una organización, módulo a módulo.
 *
 * Se devuelve al usuario y se guarda en la auditoría: el derecho de supresión
 * no es «hazlo y ya», es «dime qué has hecho».
 */
final readonly class RemovalReport
{
    /**
     * @param array<string, int> $removedByModule
     */
    public function __construct(public array $removedByModule = [])
    {
    }

    public function total(): int
    {
        return array_sum($this->removedByModule);
    }

    public function isEmpty(): bool
    {
        return 0 === $this->total();
    }
}
