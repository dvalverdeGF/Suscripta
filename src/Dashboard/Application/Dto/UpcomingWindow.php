<?php

declare(strict_types=1);

namespace App\Dashboard\Application\Dto;

use App\Services\Domain\Entity\Service;
use App\Shared\Domain\ValueObject\Money;

/**
 * Cobros previstos dentro de una ventana de tiempo.
 *
 * El panel muestra dos ventanas (30 y 60 días) porque responden a preguntas
 * distintas: la de 30 días es «qué me van a cobrar este mes» y la de 60 es «qué
 * me van a cobrar antes de que pueda reaccionar». Los totales se agrupan por
 * moneda por la misma razón que en el resto del producto: sumar euros con
 * dólares daría un número que no significa nada (ARCHITECTURE.md §10).
 */
final readonly class UpcomingWindow
{
    /**
     * @param list<Service>        $charges          ordenados por fecha de cobro
     * @param array<string, Money> $totalsByCurrency clave = código ISO de la moneda
     */
    public function __construct(
        public int $days,
        public array $charges,
        public array $totalsByCurrency,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->charges;
    }
}
