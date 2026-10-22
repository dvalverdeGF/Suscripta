<?php

declare(strict_types=1);

namespace App\Ai\Application;

use App\Ai\Application\Dto\AiBudgetStatus;
use App\Ai\Domain\Entity\AiBudget;
use App\Ai\Domain\Enum\AiBudgetMode;
use App\Ai\Domain\Repository\AiBudgetRepositoryInterface;
use App\Ai\Domain\Repository\AiUsageRepositoryInterface;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\DateRange;
use Symfony\Component\Uid\Uuid;

/**
 * Vigila el presupuesto de IA de la organización activa (Fase 14, D-55).
 *
 * ## Qué decide
 *
 * Tres preguntas, y solo tres:
 *
 * 1. ¿Se puede hacer una llamada a un modelo ahora mismo?
 * 2. ¿Se puede hacer una llamada a un modelo **avanzado** ahora mismo?
 * 3. ¿Cuánto se ha gastado y cuánto queda?
 *
 * ## De dónde sale el gasto
 *
 * Del **libro mayor** (`AiUsage`), no de un contador guardado aquí. El libro
 * mayor es la fuente de verdad: cada llamada deja una fila con su coste
 * real. Si aquí se guardara un contador, habría dos verdades —el contador y la
 * suma de las filas— y se contradirían en cuanto una escritura fallara
 * (D-55).
 *
 * Lo que sí se guarda es la **política**: el tope y qué hacer al llegar a él.
 * Eso no se puede deducir del histórico.
 *
 * ## Degradar o parar
 *
 * Al agotarse el presupuesto, el comportamiento depende del modo elegido
 * (D-56):
 *
 * - `SOFT`: se sigue usando la IA económica y se apaga la avanzada.
 * - `HARD`: no se llama a ningún modelo, y los correos que necesitaban IA
 *   quedan aplazados (`DEFERRED`) hasta el mes siguiente.
 */
final readonly class AiBudgetGuard
{
    public function __construct(
        private AiBudgetRepositoryInterface $budgets,
        private AiUsageRepositoryInterface $usage,
        private TenantContext $tenantContext,
        private Clock $clock,
        /**
         * Tope mensual por defecto, en céntimos, para las organizaciones que
         * no hayan configurado uno. `0` significa «sin tope».
         */
        private int $defaultLimitMinor = 500,
        /**
         * Modo por defecto al agotarse el presupuesto.
         */
        private AiBudgetMode $defaultMode = AiBudgetMode::SOFT,
        /**
         * Porcentaje de uso a partir del cual se avisa al usuario.
         */
        private int $warningPercent = 80,
    ) {
    }

    /**
     * El presupuesto persistido de la organización activa para el mes en
     * curso, o `null` si todavía no se ha configurado ninguno.
     */
    public function budget(): ?AiBudget
    {
        $organizationId = $this->tenantContext->getOrganizationId();

        if (null === $organizationId) {
            return null;
        }

        return $this->budgets->find($organizationId, $this->currentPeriodKey());
    }

    /**
     * Tope mensual en céntimos (`0` = sin tope).
     */
    public function limitMinor(): int
    {
        return $this->budget()?->getLimitMinor() ?? $this->defaultLimitMinor;
    }

    /**
     * Qué pasa cuando se agota el presupuesto.
     */
    public function mode(): AiBudgetMode
    {
        return $this->budget()?->getMode() ?? $this->defaultMode;
    }

    /**
     * Gasto del mes en curso, en céntimos.
     */
    public function spentMinor(): int
    {
        $organizationId = $this->tenantContext->getOrganizationId();

        if (null === $organizationId) {
            return 0;
        }

        return $this->usage->totalCostMinor($organizationId, $this->currentPeriod());
    }

    /**
     * Lo que queda de presupuesto, en céntimos. Nunca es negativo.
     */
    public function remainingMinor(): int
    {
        $limit = $this->limitMinor();

        if ($limit <= 0) {
            return 0;
        }

        return max(0, $limit - $this->spentMinor());
    }

    /**
     * ¿Se ha agotado el presupuesto?
     */
    public function isExhausted(): bool
    {
        $limit = $this->limitMinor();

        if ($limit <= 0) {
            return false;
        }

        return $this->spentMinor() >= $limit;
    }

    /**
     * ¿Se permite una llamada a un modelo?
     *
     * En modo `SOFT` agotado **sí** se permite: lo que se apaga es el nivel
     * avanzado, no la IA entera.
     */
    public function allowsCall(): bool
    {
        if (!$this->isExhausted()) {
            return true;
        }

        return AiBudgetMode::HARD !== $this->mode();
    }

    /**
     * ¿Se permite una llamada a un modelo de este nivel?
     */
    public function allowsTier(ExtractionTier $tier): bool
    {
        if (!$tier->usedAi()) {
            return true;
        }

        if (!$this->isExhausted()) {
            return true;
        }

        // Agotado y en modo duro: no se llama a ningún modelo.
        if (AiBudgetMode::HARD === $this->mode()) {
            return false;
        }

        // Agotado y en modo blando: solo se apaga el nivel caro.
        return ExtractionTier::AI_ADVANCED !== $tier;
    }

    /**
     * Una foto completa del presupuesto, para pintarla.
     */
    public function status(): AiBudgetStatus
    {
        $limit = $this->limitMinor();
        $spent = $this->spentMinor();

        $usedPercent = 0;

        if ($limit > 0) {
            $usedPercent = (int) round(($spent / $limit) * 100);
        }

        return new AiBudgetStatus(
            limitMinor: $limit,
            spentMinor: $spent,
            remainingMinor: $this->remainingMinor(),
            mode: $this->mode(),
            exhausted: $this->isExhausted(),
            usedPercent: $usedPercent,
            warning: $limit > 0 && $usedPercent >= $this->warningPercent,
        );
    }

    /**
     * Guarda el tope y el modo de la organización activa.
     *
     * Si la organización no tenía presupuesto para este mes, se crea uno con
     * esos valores: la fila nace aquí, no en la lectura.
     */
    public function configure(int $limitMinor, AiBudgetMode $mode): AiBudget
    {
        $organizationId = $this->tenantContext->requireOrganizationId();
        $period = $this->currentPeriodKey();
        $now = $this->clock->now();

        $budget = $this->budgets->find($organizationId, $period);

        if (null === $budget) {
            $budget = new AiBudget(
                organizationId: $organizationId,
                period: $period,
                limitMinor: $limitMinor,
                mode: $mode,
                now: $now,
            );
        } else {
            $budget->changeLimit($limitMinor, $now);
            $budget->changeMode($mode, $now);
        }

        $this->budgets->save($budget);

        return $budget;
    }

    /**
     * Rango del mes natural en curso.
     */
    public function currentPeriod(): DateRange
    {
        $now = $this->clock->now();

        return new DateRange(
            $now->modify('first day of this month 00:00:00'),
            $now->modify('first day of next month 00:00:00'),
        );
    }

    /**
     * Clave del periodo en curso: `YYYY-MM`.
     */
    public function currentPeriodKey(): string
    {
        return AiBudget::periodOf($this->clock->now());
    }

    /**
     * Identificador de la organización activa, si la hay.
     */
    public function organizationId(): ?Uuid
    {
        return $this->tenantContext->getOrganizationId();
    }
}
