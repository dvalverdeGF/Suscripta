<?php

declare(strict_types=1);

namespace App\Services\Domain\Dto;

use App\Shared\Domain\ValueObject\Money;

use function count;

use DateTimeImmutable;

/**
 * Lo que costaba el inventario en un mes concreto.
 *
 * Se guarda por moneda porque el gasto de un mes puede estar repartido en
 * varias divisas y sumarlas daría un número que no significa nada
 * (ARCHITECTURE.md §10).
 */
final readonly class SpendPoint
{
    /**
     * @param array<string, Money> $byCurrency clave = código ISO de la moneda
     */
    public function __construct(
        public DateTimeImmutable $month,
        public array $byCurrency,
        public int $serviceCount,
    ) {
    }

    /**
     * Importe del mes cuando todo el inventario está en la misma moneda.
     */
    public function singleTotal(): ?Money
    {
        return 1 === count($this->byCurrency) ? array_values($this->byCurrency)[0] : null;
    }
}
