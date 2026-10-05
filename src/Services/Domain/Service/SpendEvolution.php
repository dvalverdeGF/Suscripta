<?php

declare(strict_types=1);

namespace App\Services\Domain\Service;

use App\Services\Domain\Dto\SpendPoint;
use App\Services\Domain\Entity\Service;
use App\Shared\Domain\ValueObject\Money;

use function array_values;

use DateTimeImmutable;

use function is_array;
use function iterator_to_array;
use function max;
use function round;

/**
 * Evolución del gasto recurrente mes a mes.
 *
 * Responde a «¿estoy gastando más que antes?», que es la pregunta que un total
 * actual no contesta. El cálculo es **histórico**: para cada mes se usa el
 * precio que estaba vigente entonces, no el de hoy. Proyectar el precio actual
 * hacia atrás daría una línea plana y mentiría justo cuando el usuario acaba de
 * sufrir una subida.
 *
 * Limitaciones conocidas y deliberadas:
 * - Un servicio pausado hoy se cuenta en todos los meses, porque la pausa es un
 *   estado actual y no hay histórico de pausas. Se documenta en la vista.
 * - Un servicio cancelado deja de contar a partir del mes de la cancelación.
 * - Un servicio sin fecha de alta cuenta desde el primer mes de la ventana.
 */
final class SpendEvolution
{
    /**
     * @param iterable<Service> $services
     *
     * @return list<SpendPoint> del mes más antiguo al más reciente
     */
    public function monthly(iterable $services, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<Service> $services */
        $services = is_array($services) ? array_values($services) : iterator_to_array($services, false);

        $points = [];
        $cursor = $from->modify('first day of this month')->setTime(0, 0);
        $last = $to->modify('first day of this month')->setTime(0, 0);

        while ($cursor <= $last) {
            $points[] = $this->pointAt($services, $cursor);
            $cursor = $cursor->modify('+1 month');
        }

        return $points;
    }

    /**
     * @param list<Service> $services
     */
    private function pointAt(array $services, DateTimeImmutable $month): SpendPoint
    {
        $totals = [];
        $count = 0;

        foreach ($services as $service) {
            if (!$this->wasBillableIn($service, $month)) {
                continue;
            }

            $price = $service->getPriceAt($month);

            if (null === $price) {
                continue;
            }

            $monthly = $this->monthlyEquivalent($service, $price->getAmount());

            if (null === $monthly) {
                continue;
            }

            $code = $monthly->currency->value;
            $totals[$code] = isset($totals[$code]) ? $totals[$code]->add($monthly) : $monthly;
            ++$count;
        }

        return new SpendPoint(month: $month, byCurrency: $totals, serviceCount: $count);
    }

    /**
     * ¿Estaba el servicio dado de alta y sin cancelar en ese mes?
     */
    private function wasBillableIn(Service $service, DateTimeImmutable $month): bool
    {
        $startedAt = $service->getStartedAt();

        if (null !== $startedAt && $startedAt > $month->modify('last day of this month')) {
            return false;
        }

        $cancelledAt = $service->getCancelledAt();

        return null === $cancelledAt || $cancelledAt >= $month;
    }

    /**
     * Equivalente mensual de un importe concreto, con la periodicidad actual
     * del servicio.
     *
     * La periodicidad no tiene historial: si un servicio pasó de mensual a
     * anual, la evolución usa la periodicidad de hoy para todos los meses. Es
     * una aproximación consciente; el historial de precios sí es exacto.
     */
    private function monthlyEquivalent(Service $service, Money $amount): ?Money
    {
        $base = $service->getBillingPeriod()->occurrencesPerYear();

        if (null === $base) {
            return null;
        }

        $occurrences = $base / max(1, $service->getBillingIntervalCount());

        if ($occurrences <= 0.0) {
            return null;
        }

        return Money::of((int) round($amount->amountMinor * $occurrences / 12), $amount->currency);
    }
}
