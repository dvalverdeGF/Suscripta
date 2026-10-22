<?php

declare(strict_types=1);

namespace App\Notifications\Application\Dto;

/**
 * El resultado de revisar el presupuesto de IA de la organización (Fase 14).
 *
 * @see \App\Notifications\Application\GenerateCostAlerts
 */
final readonly class CostAlertResult
{
    public function __construct(
        public int $created,
        public int $resolved,
        public int $unchanged,
        public bool $exhausted,
        public bool $warning,
        /**
         * Avisos que el sistema había resuelto y que han vuelto a abrirse
         * porque la situación se ha repetido dentro del mismo mes.
         */
        public int $reopened = 0,
    ) {
    }

    /**
     * ¿No ha pasado nada?
     */
    public function isQuiet(): bool
    {
        return 0 === $this->created && 0 === $this->resolved && 0 === $this->reopened;
    }
}
