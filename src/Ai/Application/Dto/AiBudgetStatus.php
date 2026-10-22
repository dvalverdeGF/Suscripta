<?php

declare(strict_types=1);

namespace App\Ai\Application\Dto;

use App\Ai\Domain\Enum\AiBudgetMode;

/**
 * Una foto del presupuesto de IA en este momento (Fase 14).
 *
 * Es lo que pintan el panel de observabilidad y la pantalla de ajustes, y lo
 * que decide si la escalera de IA puede llamar a un modelo o no.
 *
 * ## El porcentaje va de 0 a 100
 *
 * Se calcula sobre el tope, no sobre el gasto: un 120 % significa que se ha
 * pasado del tope, y se muestra como tal en vez de recortarse, porque un panel
 * que dice «100 %» cuando el gasto es del 130 % está mintiendo.
 */
final readonly class AiBudgetStatus
{
    public function __construct(
        public int $limitMinor,
        public int $spentMinor,
        public int $remainingMinor,
        public AiBudgetMode $mode,
        public bool $exhausted,
        public int $usedPercent,
        public bool $warning,
    ) {
    }

    /**
     * ¿El presupuesto no pone ningún tope?
     */
    public function isUnlimited(): bool
    {
        return $this->limitMinor <= 0;
    }

    /**
     * ¿Se ha pasado del tope?
     */
    public function isOverLimit(): bool
    {
        return $this->isUnlimited() || $this->spentMinor > $this->limitMinor;
    }
}
