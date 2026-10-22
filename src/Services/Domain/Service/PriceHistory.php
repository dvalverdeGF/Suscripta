<?php

declare(strict_types=1);

namespace App\Services\Domain\Service;

use App\Services\Domain\Dto\PriceHistoryEntry;
use App\Services\Domain\Dto\ServicePriceView;
use App\Services\Domain\Dto\ServiceView;
use App\Shared\Domain\ValueObject\Money;

use function count;
use function max;
use function usort;

/**
 * Historial de precios de un servicio, con la variación de cada fila.
 *
 * El historial es **inmutable**: `Service::changePrice()` cierra la fila
 * anterior y añade una nueva, nunca reescribe. Esta clase solo lo lee y lo
 * ordena, así que puede recalcularse en cualquier momento sin riesgo.
 *
 * La variación se mide contra la fila inmediatamente anterior en el tiempo, no
 * contra la primera: lo que el usuario quiere saber es «cuánto me ha subido
 * esta vez», no «cuánto me ha subido desde que me di de alta».
 *
 * Lee de `ServiceView`, no de la entidad: quien muestra el historial (el
 * backoffice) no necesita permiso de escritura sobre el inventario
 * (ARCHITECTURE.md §3).
 */
final class PriceHistory
{
    /**
     * @return list<PriceHistoryEntry>
     */
    public function entries(ServiceView $service): array
    {
        // Copia local: `ServiceView` es de solo lectura y su propiedad no se
        // puede ordenar en su sitio.
        $prices = $service->prices;

        if ([] === $prices) {
            return [];
        }

        usort(
            $prices,
            static fn (ServicePriceView $a, ServicePriceView $b): int => $a->validFrom <=> $b->validFrom,
        );

        $current = $service->currentPrice;
        $entries = [];
        $previous = null;

        foreach ($prices as $price) {
            $amount = $price->amount;
            $variation = null === $previous ? null : $amount->subtract($previous->amount);
            $ratio = null === $previous ? null : $amount->relativeDifferenceTo($previous->amount);

            $entries[] = new PriceHistoryEntry(
                price: $price,
                amount: $amount,
                validFrom: $price->validFrom,
                validTo: $price->validTo,
                source: $price->source,
                note: $price->note,
                isCurrent: $price === $current,
                variationAbsolute: $variation,
                variationRatio: $ratio,
            );

            $previous = $price;
        }

        return $entries;
    }

    /**
     * Diferencia entre el primer precio conocido y el vigente.
     *
     * Null si no hay al menos dos precios: con uno solo no hay evolución que
     * contar, y devolver cero haría creer que el precio nunca ha cambiado.
     */
    public function totalVariation(ServiceView $service): ?Money
    {
        $entries = $this->entries($service);

        if (count($entries) < 2) {
            return null;
        }

        return $entries[count($entries) - 1]->amount->subtract($entries[0]->amount);
    }

    /**
     * Variación porcentual entre el primer precio conocido y el vigente.
     */
    public function totalVariationRatio(ServiceView $service): ?float
    {
        $entries = $this->entries($service);

        if (count($entries) < 2) {
            return null;
        }

        return $entries[count($entries) - 1]->amount->relativeDifferenceTo($entries[0]->amount);
    }

    /**
     * Cuántas veces ha cambiado el precio.
     */
    public function changeCount(ServiceView $service): int
    {
        $entries = $this->entries($service);

        return max(0, count($entries) - 1);
    }
}
