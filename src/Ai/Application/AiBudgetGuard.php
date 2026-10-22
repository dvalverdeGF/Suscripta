<?php

declare(strict_types=1);

namespace App\Ai\Application;

use App\Ai\Application\Dto\AiBudgetStatus;
use App\Ai\Domain\Entity\AiBudget;
use App\Ai\Domain\Enum\AiBudgetMode;
use App\Ai\Domain\Repository\AiBudgetRepositoryInterface;
use App\Ai\Domain\Repository\AiUsageRepositoryInterface;
use App\Ai\Domain\ValueObject\AiCost;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Shared\Application\Clock;
use App\Shared\Application\Entitlements\Capability;
use App\Shared\Application\Entitlements\EntitlementCheckerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\DateRange;

use function sprintf;

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
         * Techo que impone el plan (Fase 17). Va antes que los parámetros
         * opcionales porque un parámetro con valor por defecto no puede
         * preceder a uno obligatorio.
         */
        private EntitlementCheckerInterface $entitlements,
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
     * El tope que la organización ha puesto para este mes, en céntimos.
     *
     * `null` = no ha configurado ninguno (manda el del plan). `0` = ha dicho
     * explícitamente «sin tope».
     */
    public function ownLimitMinor(): ?int
    {
        return $this->budget()?->getLimitMinor();
    }

    /**
     * Tope mensual efectivo en céntimos (`0` = sin tope o sin IA en el plan).
     *
     * Es el **menor** de los dos topes que existen: el que la organización ha
     * configurado y el que impone su plan (Fase 17). El plan manda siempre:
     * un plan no puede waive su propio techo porque el usuario haya puesto
     * un presupuesto mayor.
     */
    public function limitMinor(): int
    {
        $planLimit = $this->planLimitMinor();

        // El plan no ofrece IA: no hay presupuesto que valga.
        if (null !== $planLimit && $planLimit <= 0) {
            return 0;
        }

        $own = $this->ownLimitMinor();

        // La organización no ha configurado ningún tope: manda el del plan y,
        // si el plan no pone ninguno, el tope por defecto de la aplicación.
        if (null === $own) {
            return $planLimit ?? $this->defaultLimitMinor;
        }

        // La organización ha dicho explícitamente «sin tope» (0). El plan puede
        // acotarlo, pero no se lo quitan.
        if ($own <= 0) {
            return $planLimit ?? 0;
        }

        if (null === $planLimit) {
            return $own;
        }

        return min($own, $planLimit);
    }

    /**
     * Techo que impone el plan para la IA, en céntimos.
     *
     * `null` = el plan no pone techo (manda el presupuesto propio).
     * `0` = el plan no ofrece IA.
     */
    public function planLimitMinor(): ?int
    {
        return $this->entitlements->limit(Capability::AI_BUDGET_MINOR);
    }

    /**
     * ¿El plan ofrece inteligencia artificial?
     */
    public function planAllowsAi(): bool
    {
        return $this->entitlements->allows(Capability::AI);
    }

    /**
     * ¿Se puede usar IA con este plan?
     *
     * Son dos cosas: que el plan la ofrezca y que le dé presupuesto. Un plan
     * que ofrece IA pero no le da presupuesto no puede llamar a ningún
     * modelo, y decirlo así evita el caso raro de «tiene IA pero no puede
     * usarla».
     */
    public function isAiAvailable(): bool
    {
        if (!$this->planAllowsAi()) {
            return false;
        }

        $planLimit = $this->planLimitMinor();

        return null === $planLimit || $planLimit > 0;
    }

    /**
     * Qué pasa cuando se agota el presupuesto.
     */
    public function mode(): AiBudgetMode
    {
        return $this->budget()?->getMode() ?? $this->defaultMode;
    }

    /**
     * Gasto del mes en curso, en **microcéntimos** (D-68).
     *
     * El gasto se compara con el tope en microcéntimos: el tope es una
     * cantidad redonda, pero el gasto es la suma exacta de miles de
     * llamadas, y compararla redondeada dejaría gastar de más.
     */
    public function spentMicro(): int
    {
        $organizationId = $this->tenantContext->getOrganizationId();

        if (null === $organizationId) {
            return 0;
        }

        return $this->usage->totalCostMicro($organizationId, $this->currentPeriod());
    }

    /**
     * Gasto del mes en curso, en céntimos.
     */
    public function spentMinor(): int
    {
        return AiCost::toMinor($this->spentMicro());
    }

    /**
     * Tope efectivo en microcéntimos (`0` = sin tope o sin IA en el plan).
     */
    public function limitMicro(): int
    {
        return AiCost::toMicro($this->limitMinor());
    }

    /**
     * Lo que queda de presupuesto, en microcéntimos. Nunca es negativo.
     */
    public function remainingMicro(): int
    {
        $limit = $this->limitMicro();

        if ($limit <= 0) {
            return 0;
        }

        return max(0, $limit - $this->spentMicro());
    }

    /**
     * Lo que queda de presupuesto, en céntimos. Nunca es negativo.
     */
    public function remainingMinor(): int
    {
        return AiCost::toMinor($this->remainingMicro());
    }

    /**
     * ¿Se ha agotado el presupuesto?
     */
    public function isExhausted(): bool
    {
        $limit = $this->limitMicro();

        if ($limit <= 0) {
            return false;
        }

        return $this->spentMicro() >= $limit;
    }

    /**
     * ¿Se permite una llamada a un modelo?
     *
     * En modo `SOFT` agotado **sí** se permite: lo que se apaga es el nivel
     * avanzado, no la IA entera.
     */
    public function allowsCall(): bool
    {
        if (!$this->isAiAvailable()) {
            return false;
        }

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

        if (!$this->isAiAvailable()) {
            return false;
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
        $limit = $this->limitMicro();
        $spent = $this->spentMicro();

        $usedPercent = 0;

        if ($limit > 0) {
            $usedPercent = (int) round(($spent / $limit) * 100);
        }

        $ownLimit = $this->ownLimitMinor();
        $planLimit = $this->planLimitMinor();

        return new AiBudgetStatus(
            limitMicro: $limit,
            spentMicro: $spent,
            remainingMicro: $this->remainingMicro(),
            mode: $this->mode(),
            exhausted: $this->isExhausted(),
            usedPercent: $usedPercent,
            warning: $limit > 0 && $usedPercent >= $this->warningPercent,
            available: $this->isAiAvailable(),
            ownLimitMicro: null === $ownLimit ? null : AiCost::toMicro($ownLimit),
            planLimitMicro: null === $planLimit ? null : AiCost::toMicro($planLimit),
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
        $this->assertWithinPlan($limitMinor);

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
     * El presupuesto que se quiere guardar tiene que caber en el plan.
     *
     * No se recorta en silencio: si alguien pide más de lo que su plan
     * permite, se lo dice, porque un presupuesto recortado en silencio
     * dejaría al usuario creyendo que tiene más margen del que tiene.
     */
    private function assertWithinPlan(int $limitMinor): void
    {
        if (!$this->isAiAvailable()) {
            throw new InvalidArgumentException('El plan de la organización no incluye inteligencia artificial.');
        }

        $planLimit = $this->planLimitMinor();

        if (null === $planLimit || $limitMinor <= $planLimit) {
            return;
        }

        throw new InvalidArgumentException(sprintf('El plan permite un presupuesto de IA de %d céntimos como máximo.', $planLimit));
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
