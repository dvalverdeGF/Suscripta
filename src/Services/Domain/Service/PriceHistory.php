<?php

declare(strict_types=1);

namespace App\Services\Domain\Service;

use App\Services\Domain\Dto\PriceHistoryEntry;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Entity\ServicePrice;
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
 */
final class PriceHistory
{
    /**
     * @return list<PriceHistoryEntry>
     */
    public function entries(Service $service): array
    {
        $prices = $service->getPrices()->toArray();

        if ([] === $prices) {
            return [];
        }

        usort(
            $prices,
            static fn (ServicePrice $a, ServicePrice $b): int => $a->getValidFrom() <=> $b->getValidFrom(),
        );

        $current = $service->getCurrentPrice();
        $entries = [];
        $previous = null;

        foreach ($prices as $price) {
            $amount = $price->getAmount();
            $variation = null === $previous ? null : $amount->subtract($previous->getAmount());
            $ratio = null === $previous ? null : $amount->relativeDifferenceTo($previous->getAmount());

            $entries[] = new PriceHistoryEntry(
                price: $price,
                amount: $amount,
                validFrom: $price->getValidFrom(),
                validTo: $price->getValidTo(),
                source: $price->getSource(),
                note: $price->getNote(),
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
    public function totalVariation(Service $service): ?Money
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
    public function totalVariationRatio(Service $service): ?float
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
    public function changeCount(Service $service): int
    {
        $entries = $this->entries($service);

        return max(0, count($entries) - 1);
    }
}
