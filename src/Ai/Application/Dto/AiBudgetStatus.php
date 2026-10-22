<?php

declare(strict_types=1);

namespace App\Ai\Application\Dto;

use App\Ai\Domain\Enum\AiBudgetMode;
use App\Ai\Domain\ValueObject\AiCost;

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
 *
 * ## Los importes van en microcéntimos
 *
 * El gasto se guarda y se compara en microcéntimos (D-68): el tope es una
 * cantidad redondeada a céntimos, pero el gasto es la suma exacta de miles
 * de llamadas, y comparar un total redondeado contra un tope dejaría pasar
 * de largo el presupuesto. Los importes en céntimos (`limitMinor()`,
 * `spentMinor()`) se derivan de aquí, no al revés.
 */
final readonly class AiBudgetStatus
{
    public function __construct(
        public int $limitMicro,
        public int $spentMicro,
        public int $remainingMicro,
        public AiBudgetMode $mode,
        public bool $exhausted,
        public int $usedPercent,
        public bool $warning,
        /**
         * ¿El plan de la organización ofrece inteligencia artificial (Fase 17)?
         *
         * Es distinto de «sin tope»: un plan sin IA tiene un límite de cero
         * porque no se puede gastar, no porque no haya límite.
         */
        public bool $available = true,
        /**
         * El tope que se ha puesto la organización, en céntimos.
         *
         * `null` = la organización no ha configurado presupuesto este mes, así
         * que manda el del plan. `0` = la organización ha dicho explícitamente
         * «sin tope» (y, si el plan no pone ninguno, no hay ninguno).
         */
        public ?int $ownLimitMicro = null,
        /**
         * El techo que impone el plan, en céntimos.
         *
         * `null` = el plan no pone techo. `0` = el plan no ofrece IA (y entonces
         * `available` ya es `false`).
         */
        public ?int $planLimitMicro = null,
    ) {
    }

    /**
     * El tope efectivo, en céntimos.
     */
    public function limitMinor(): int
    {
        return AiCost::toMinor($this->limitMicro);
    }

    /**
     * El gasto del mes, en céntimos.
     */
    public function spentMinor(): int
    {
        return AiCost::toMinor($this->spentMicro);
    }

    /**
     * Lo que queda, en céntimos.
     */
    public function remainingMinor(): int
    {
        return AiCost::toMinor($this->remainingMicro);
    }

    /**
     * ¿El tope que se ve es el del plan y no el de la organización?
     *
     * Es un caso que merece explicarse en pantalla: si la organización no ha
     * puesto tope, lo que aparece es el que trae el plan, y no es lo mismo
     * («lo elegiste tú» que «te lo damos nosotros»).
     */
    public function isLimitedByPlan(): bool
    {
        if (null === $this->planLimitMicro || $this->planLimitMicro <= 0) {
            return false;
        }

        // Lo que se ve es exactamente el techo del plan: es el plan el que
        // está marcando el límite, lo haya puesto quien lo haya puesto.
        return $this->limitMicro === $this->planLimitMicro;
    }

    /**
     * ¿El presupuesto no pone ningún tope?
     */
    public function isUnlimited(): bool
    {
        return $this->available && $this->limitMicro <= 0;
    }

    /**
     * ¿Se ha pasado del tope?
     */
    public function isOverLimit(): bool
    {
        if ($this->isUnlimited() || !$this->available) {
            return false;
        }

        return $this->spentMicro > $this->limitMicro;
    }
}
