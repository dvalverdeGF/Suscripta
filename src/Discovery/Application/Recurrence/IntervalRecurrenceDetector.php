<?php

declare(strict_types=1);

namespace App\Discovery\Application\Recurrence;

use App\Discovery\Domain\Recurrence\RecurrenceDetectorInterface;
use App\Discovery\Domain\Recurrence\RecurrenceResult;
use App\Discovery\Domain\Recurrence\RecurrenceSample;
use App\Shared\Domain\ValueObject\BillingPeriod;

use function array_sum;
use function count;

use DateTimeImmutable;

use function max;
use function min;
use function round;
use function sprintf;
use function usort;

/**
 * Detecta la periodicidad a partir de las distancias entre cobros.
 *
 * El algoritmo es deliberadamente conservador:
 *
 *  1. Se ordenan los cobros por fecha y se calculan los intervalos en días.
 *  2. Cada intervalo se mapea a una periodicidad conocida (`BillingPeriod::fromDayDistance()`).
 *  3. Si **todos** los intervalos caen en la misma periodicidad, el patrón es regular.
 *  4. Si no, el cobro es irregular: se informa de la confianza baja y **no** se propone
 *     fecha de próximo cobro (ARCHITECTURE.md §14).
 *
 * La confianza combina tres factores: que los intervalos coincidan (consistencia),
 * que estén bien centrados respecto a la duración nominal (precisión) y que haya
 * historial suficiente (muestras).
 */
final readonly class IntervalRecurrenceDetector implements RecurrenceDetectorInterface
{
    /**
     * Tolerancia admitida respecto a la duración nominal del periodo.
     *
     * Los meses no tienen todos 30 días: sin margen, un cobro mensual real
     * (28 o 31 días) se descartaría por ser "irregular".
     */
    private const float TOLERANCE = 0.15;

    /**
     * Mínimo de cobros para poder hablar de un patrón.
     */
    private const int MIN_SAMPLES = 2;

    /**
     * Tope de iteraciones al avanzar una fecha (ver `nextOccurrence()`).
     */
    private const int MAX_ITERATIONS = 200;

    public function detect(array $samples, ?DateTimeImmutable $now = null): RecurrenceResult
    {
        if (count($samples) < self::MIN_SAMPLES) {
            return new RecurrenceResult(
                explanation: 'Hacen falta al menos dos cobros para deducir la periodicidad.',
            );
        }

        $ordered = $this->sortByDate($samples);
        $intervals = $this->intervals($ordered);

        $last = $ordered[count($ordered) - 1];
        $amounts = $this->knownAmounts($ordered);
        $amountMinor = $amounts[count($amounts) - 1] ?? null;
        $previousAmountMinor = count($amounts) > 1 ? $amounts[count($amounts) - 2] : null;
        $amountChanged = null !== $amountMinor && null !== $previousAmountMinor && $amountMinor !== $previousAmountMinor;

        if ([] === $intervals) {
            return new RecurrenceResult(
                amountMinor: $amountMinor,
                currency: $this->currencyOf($ordered),
                previousAmountMinor: $previousAmountMinor,
                amountChanged: $amountChanged,
                explanation: 'Todos los cobros tienen la misma fecha: no hay patrón de periodicidad.',
            );
        }

        $period = $this->dominantPeriod($intervals);

        if (null === $period) {
            return new RecurrenceResult(
                isRegular: false,
                confidence: 0.0,
                lastChargeAt: $last->chargedAt,
                amountMinor: $amountMinor,
                currency: $this->currencyOf($ordered),
                previousAmountMinor: $previousAmountMinor,
                amountChanged: $amountChanged,
                intervalsDays: $intervals,
                explanation: 'Los intervalos entre cobros no encajan con ninguna periodicidad conocida: cobro irregular.',
            );
        }

        $mismatches = $this->mismatches($intervals, $period);
        $isRegular = [] === $mismatches;

        $consistency = (count($intervals) - count($mismatches)) / count($intervals);
        $precision = $this->precision($intervals, $period);
        $history = min(1.0, 0.8 + 0.1 * count($intervals));
        $confidence = round($consistency * $precision * $history, 4);

        $nextChargeAt = null;

        if ($isRegular) {
            $nextChargeAt = $this->nextOccurrence($last->chargedAt, $period, $now);
        }

        return new RecurrenceResult(
            period: $period,
            isRegular: $isRegular,
            confidence: $confidence,
            lastChargeAt: $last->chargedAt,
            nextChargeAt: $nextChargeAt,
            amountMinor: $amountMinor,
            currency: $this->currencyOf($ordered),
            previousAmountMinor: $previousAmountMinor,
            amountChanged: $amountChanged,
            intervalsDays: $intervals,
            explanation: $this->explain($period, $intervals, $isRegular),
        );
    }

    /**
     * @param list<RecurrenceSample> $samples
     *
     * @return list<RecurrenceSample>
     */
    private function sortByDate(array $samples): array
    {
        usort(
            $samples,
            static fn (RecurrenceSample $a, RecurrenceSample $b): int => $a->chargedAt <=> $b->chargedAt,
        );

        return $samples;
    }

    /**
     * Distancias en días entre cobros consecutivos.
     *
     * Los cobros del mismo día se ignoran: no son un intervalo, son dos
     * anotaciones del mismo cobro (una corrección, un splitting de factura).
     *
     * @param list<RecurrenceSample> $ordered
     *
     * @return list<int>
     */
    private function intervals(array $ordered): array
    {
        $intervals = [];

        for ($i = 1, $total = count($ordered); $i < $total; ++$i) {
            $diff = $ordered[$i - 1]->chargedAt->diff($ordered[$i]->chargedAt);
            $days = (int) $diff->days;

            if ($diff->invert) {
                $days = -$days;
            }

            if ($days > 0) {
                $intervals[] = $days;
            }
        }

        return $intervals;
    }

    /**
     * Periodicidad que explica la mayoría de los intervalos.
     *
     * En caso de empate gana la que mejor centra a sus intervalos respecto a
     * su duración nominal.
     *
     * @param list<int> $intervals
     */
    private function dominantPeriod(array $intervals): ?BillingPeriod
    {
        /** @var array<string, array{period: BillingPeriod, days: list<int>}> $groups */
        $groups = [];

        foreach ($intervals as $days) {
            $period = BillingPeriod::fromDayDistance($days);

            if (null === $period) {
                continue;
            }

            // Los enums no pueden ser claves de array en PHP: se agrupa por valor.
            $groups[$period->value] ??= ['period' => $period, 'days' => []];
            $groups[$period->value]['days'][] = $days;
        }

        if ([] === $groups) {
            return null;
        }

        $best = null;
        $bestCount = 0;
        $bestOffset = null;

        foreach ($groups as $group) {
            $count = count($group['days']);
            $expected = $group['period']->days() ?? 0;
            $mean = array_sum($group['days']) / $count;
            $offset = abs($mean - $expected);

            if (null === $best || $count > $bestCount || ($count === $bestCount && $offset < $bestOffset)) {
                $best = $group['period'];
                $bestCount = $count;
                $bestOffset = $offset;
            }
        }

        return $best;
    }

    /**
     * Intervalos que no encajan con la periodicidad dominante.
     *
     * @param list<int> $intervals
     *
     * @return list<int>
     */
    private function mismatches(array $intervals, BillingPeriod $period): array
    {
        $mismatches = [];

        foreach ($intervals as $days) {
            if (BillingPeriod::fromDayDistance($days) !== $period) {
                $mismatches[] = $days;
            }
        }

        return $mismatches;
    }

    /**
     * Qué tan bien centrados están los intervalos respecto a la duración nominal.
     *
     * @param list<int> $intervals
     */
    private function precision(array $intervals, BillingPeriod $period): float
    {
        $expected = $period->days();

        if (null === $expected || 0 === $expected) {
            return 0.0;
        }

        $deviation = 0.0;

        foreach ($intervals as $days) {
            $deviation += abs($days - $expected) / $expected;
        }

        $deviation /= count($intervals);

        return max(0.0, 1.0 - $deviation / (2 * self::TOLERANCE));
    }

    /**
     * Próxima fecha de cobro a partir del último cobro observado.
     *
     * Los periodos expresables en meses se avanzan desde el ancla original, no
     * desde el resultado anterior: si no, un servicio que cobra el 31 se
     * desplazaría al 28 para siempre tras su primer February.
     */
    private function nextOccurrence(DateTimeImmutable $last, BillingPeriod $period, ?DateTimeImmutable $now): DateTimeImmutable
    {
        $months = $period->months();

        if (null !== $months && null !== $now) {
            $monthsDiff = ((int) $now->format('Y') - (int) $last->format('Y')) * 12
                + ((int) $now->format('n') - (int) $last->format('n'));
            $steps = max(1, (int) ceil($monthsDiff / $months));
            $next = $period->advance($last, $steps);

            for ($i = 0; $i < self::MAX_ITERATIONS && $next < $now; ++$i) {
                ++$steps;
                $next = $period->advance($last, $steps);
            }

            return $next;
        }

        $next = $period->advance($last);

        if (null === $now) {
            return $next;
        }

        for ($i = 0; $i < self::MAX_ITERATIONS && $next < $now; ++$i) {
            $next = $period->advance($next);
        }

        return $next;
    }

    /**
     * @param list<RecurrenceSample> $ordered
     *
     * @return list<int>
     */
    private function knownAmounts(array $ordered): array
    {
        $amounts = [];

        foreach ($ordered as $sample) {
            if (null !== $sample->amountMinor) {
                $amounts[] = $sample->amountMinor;
            }
        }

        return $amounts;
    }

    /**
     * @param list<RecurrenceSample> $ordered
     */
    private function currencyOf(array $ordered): ?string
    {
        for ($i = count($ordered) - 1; $i >= 0; --$i) {
            if (null !== $ordered[$i]->currency) {
                return $ordered[$i]->currency;
            }
        }

        return null;
    }

    /**
     * @param list<int> $intervals
     */
    private function explain(BillingPeriod $period, array $intervals, bool $isRegular): string
    {
        $mean = count($intervals) > 0 ? (int) round(array_sum($intervals) / count($intervals)) : 0;

        if (!$isRegular) {
            return sprintf(
                'Los cobros no siguen una periodicidad única (intervalos de %s días): cobro irregular, no se propone próximo cobro.',
                implode(', ', $intervals),
            );
        }

        return sprintf(
            'Cobros cada %d días: periodicidad %s detectada con %d intervalo(s).',
            $mean,
            $period->label(),
            count($intervals),
        );
    }
}
