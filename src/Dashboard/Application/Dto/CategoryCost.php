<?php

declare(strict_types=1);

namespace App\Dashboard\Application\Dto;

use App\Shared\Domain\ValueObject\Money;

use function count;

/**
 * Coste recurrente de una categoría del catálogo.
 *
 * Responde a «¿en qué se me va el dinero?», que es la pregunta que un listado
 * de servicios no contesta: veinte servicios sueltos no dicen nada, pero
 * «hosting: 84 €/mes» sí.
 *
 * El coste se guarda **por moneda** y no como un único importe: una categoría
 * puede tener servicios en euros y en dólares, y sumarlos daría un número que
 * no significa nada (ARCHITECTURE.md §10).
 */
final readonly class CategoryCost
{
    /**
     * @param array<string, Money> $monthlyByCurrency clave = código ISO de la moneda
     */
    public function __construct(
        public string $categoryName,
        public array $monthlyByCurrency,
        public int $serviceCount,
    ) {
    }

    /**
     * Importe mensual cuando toda la categoría está en la misma moneda.
     */
    public function singleMonthly(): ?Money
    {
        return 1 === count($this->monthlyByCurrency) ? array_values($this->monthlyByCurrency)[0] : null;
    }

    /**
     * Importe mayor de la categoría, en cualquier moneda.
     *
     * Se usa **solo para ordenar** el desglose: comparar importes de divisas
     * distintas no tiene sentido económico, pero el usuario espera ver arriba
     * la categoría que más le cuesta, y este es el criterio más cercano.
     */
    public function sortWeight(): int
    {
        $max = 0;

        foreach ($this->monthlyByCurrency as $money) {
            $max = max($max, $money->amountMinor);
        }

        return $max;
    }
}
