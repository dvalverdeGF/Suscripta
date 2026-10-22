<?php

declare(strict_types=1);

namespace App\Services\Domain\Service;

use App\Services\Domain\Dto\ServicePriceView;
use App\Services\Domain\Dto\ServiceView;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;

use function count;

/**
 * Cálculos de coste del inventario de servicios (ARCHITECTURE.md §10).
 *
 * Todo se calcula sobre el **precio vigente** (`ServicePrice` con
 * `validTo IS NULL`) y solo para servicios activos: un servicio pausado o
 * cancelado no es un coste recurrente.
 *
 * Trabaja sobre `ServiceView`, no sobre la entidad `Service`: el cálculo es
 * una lectura, y atarlo a la entidad obligaría al dashboard y a las alertas a
 * importar `Service`, `ServicePrice` y `ServiceEvent` (ARCHITECTURE.md §3).
 *
 * Los totales se agrupan por moneda. Sumar euros con dólares daría un número
 * que no significa nada, y convertir exige un tipo de cambio que el producto no
 * tiene ni quiere inventarse.
 */
final class ServiceCostCalculator
{
    /**
     * Coste equivalente mensual del servicio, normalizado desde su periodicidad.
     *
     * Null si el servicio no es recurrente (pago único) o si no tiene precio.
     */
    public function monthlyEquivalent(ServiceView $service): ?Money
    {
        $amount = $service->currentAmount;

        if (null === $amount) {
            return null;
        }

        $occurrences = $this->occurrencesPerYear($service);

        if (null === $occurrences || $occurrences <= 0.0) {
            return null;
        }

        return Money::of((int) round($amount->amountMinor * $occurrences / 12), $amount->currency);
    }

    /**
     * Coste anual del servicio. Null si no es recurrente o no tiene precio.
     */
    public function annualCost(ServiceView $service): ?Money
    {
        $amount = $service->currentAmount;

        if (null === $amount) {
            return null;
        }

        $occurrences = $this->occurrencesPerYear($service);

        if (null === $occurrences || $occurrences <= 0.0) {
            return null;
        }

        return Money::of((int) round($amount->amountMinor * $occurrences), $amount->currency);
    }

    /**
     * Cobros al año teniendo en cuenta el intervalo ("cada 2 meses" = 6).
     */
    public function occurrencesPerYear(ServiceView $service): ?float
    {
        $base = $service->billingPeriod->occurrencesPerYear();

        if (null === $base) {
            return null;
        }

        $interval = max(1, $service->billingIntervalCount);

        return $base / $interval;
    }

    /**
     * Suma de coste mensual equivalente, agrupada por moneda.
     *
     * @param iterable<ServiceView> $services
     *
     * @return array<string, Money> clave = código ISO de la moneda
     */
    public function totalMonthlyByCurrency(iterable $services): array
    {
        return $this->sumByCurrency($services, fn (ServiceView $service): ?Money => $this->monthlyEquivalent($service));
    }

    /**
     * Suma de coste anual, agrupada por moneda.
     *
     * @param iterable<ServiceView> $services
     *
     * @return array<string, Money>
     */
    public function totalAnnualByCurrency(iterable $services): array
    {
        return $this->sumByCurrency($services, fn (ServiceView $service): ?Money => $this->annualCost($service));
    }

    /**
     * Variación porcentual del precio vigente respecto al anterior.
     *
     * Null si no hay historial suficiente o si el precio anterior era cero.
     */
    public function priceChangeRatio(ServiceView $service): ?float
    {
        // Copia local: `ServiceView` es de solo lectura y su propiedad no se
        // puede ordenar en su sitio.
        $prices = $service->prices;

        if (count($prices) < 2) {
            return null;
        }

        usort($prices, static fn (ServicePriceView $a, ServicePriceView $b): int => $a->validFrom <=> $b->validFrom);

        $current = $service->currentPrice;

        if (null === $current) {
            return null;
        }

        $previous = null;

        foreach ($prices as $price) {
            if ($price === $current) {
                continue;
            }

            if ($price->validFrom < $current->validFrom) {
                $previous = $price;
            }
        }

        if (null === $previous) {
            return null;
        }

        return $current->amount->relativeDifferenceTo($previous->amount);
    }

    /**
     * @param iterable<ServiceView>         $services
     * @param callable(ServiceView): ?Money $extractor
     *
     * @return array<string, Money>
     */
    private function sumByCurrency(iterable $services, callable $extractor): array
    {
        $totals = [];

        foreach ($services as $service) {
            if (!$service->status->countsTowardsRecurringCost()) {
                continue;
            }

            $amount = $extractor($service);

            if (null === $amount) {
                continue;
            }

            $code = $amount->currency->value;
            $totals[$code] = isset($totals[$code]) ? $totals[$code]->add($amount) : $amount;
        }

        return $totals;
    }

    /**
     * Moneda predominante del inventario, para poder mostrar un total único
     * cuando todo está en la misma divisa. Null si no hay ningún servicio con
     * precio.
     *
     * @param iterable<ServiceView> $services
     */
    public function dominantCurrency(iterable $services): ?Currency
    {
        $counts = [];

        foreach ($services as $service) {
            $amount = $service->currentAmount;

            if (null === $amount) {
                continue;
            }

            $code = $amount->currency->value;
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        if ([] === $counts) {
            return null;
        }

        arsort($counts);

        return Currency::from((string) array_key_first($counts));
    }
}
