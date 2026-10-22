<?php

declare(strict_types=1);

namespace App\Observability\Application\Dto;

/**
 * Actividad de extracción: qué ha pasado con los correos leídos.
 *
 * El dato clave es `byTier`: cuántos correos se resolvieron sin IA, cuántos
 * con un parser conocido y cuántos tuvieron que pagar con un modelo. Esa
 * proporción es la que justifica (o no) la factura de IA (D-68).
 */
final readonly class ExtractionActivity
{
    /**
     * @param array<string, int> $byTier            correos por nivel de extracción
     * @param array<string, int> $byState           correos por estado de procesamiento (actual)
     * @param int                $messagesTotal     correos procesados en el periodo
     * @param int                $deferred          correos esperando presupuesto de IA
     * @param int                $averageDurationMs tiempo medio de la recepción al fin
     */
    public function __construct(
        public array $byTier,
        public array $byState,
        public int $messagesTotal,
        public int $deferred,
        public int $averageDurationMs,
    ) {
    }

    /**
     * Correos resueltos sin llamar a un modelo.
     *
     * Es la métrica que el cliente mira para decidir si la IA está
     * justificada: el pipeline determinista debería absorber la mayoría.
     */
    public function withoutAi(): int
    {
        $total = 0;

        foreach ($this->byTier as $tier => $count) {
            if ('deterministic' === $tier || 'known_parser' === $tier) {
                $total += $count;
            }
        }

        return $total;
    }

    public function withAi(): int
    {
        return max(0, $this->messagesTotal - $this->withoutAi());
    }

    /**
     * Porcentaje resuelto sin IA (0 a 1).
     */
    public function deterministicRatio(): float
    {
        if ($this->messagesTotal === 0) {
            return 0.0;
        }

        return $this->withoutAi() / $this->messagesTotal;
    }
}
