<?php

declare(strict_types=1);

namespace App\Ai\Application;

use App\Ai\Application\Dto\AiBudgetStatus;
use App\Ai\Application\Dto\AiCostReport;
use App\Ai\Domain\Repository\AiUsageRepositoryInterface;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\DateRange;
use Symfony\Component\Uid\Uuid;

/**
 * Calcula el informe de coste y rendimiento de la IA (Fase 14).
 *
 * ## Por qué un servicio y no la plantilla
 *
 * El panel necesita cruzar dos fuentes que viven en tablas distintas: el
 * libro mayor de uso de IA (`AiUsage`) y los mensajes procesados
 * (`EmailMessage`). Cruzar eso en la plantilla significaría consultar y
 * agregar en Twig, que es exactamente donde no se debe hacer: el coste se
 * calcula en PHP y se pinta.
 *
 * ## El periodo es el mes natural
 *
 * Es el mismo periodo que usa el presupuesto (D-55), y por el mismo motivo:
 * las facturas de los proveedores de IA van por mes natural, así que el mes
 * de Suscripta y el mes de la factura se comparan sin traducción.
 */
final readonly class AiCostCalculator
{
    public function __construct(
        private AiUsageRepositoryInterface $usage,
        private EmailMessageRepositoryInterface $messages,
        private AiBudgetGuard $budget,
        private TenantContext $tenantContext,
        private Clock $clock,
    ) {
    }

    /**
     * El informe del mes en curso.
     */
    public function currentMonth(): AiCostReport
    {
        return $this->report($this->currentPeriod());
    }

    /**
     * El informe de un periodo concreto.
     */
    public function report(DateRange $period): AiCostReport
    {
        $organizationId = $this->organizationId();
        $budget = $this->budget->status();

        if (null === $organizationId) {
            return $this->emptyReport($budget);
        }

        $summary = $this->usage->usageSummary($organizationId, $period);
        $stats = $this->messages->extractionStats($organizationId, $period);

        return new AiCostReport(
            budget: $budget,
            totalCostMicro: $this->usage->totalCostMicro($organizationId, $period),
            calls: $summary['calls'],
            promptTokens: $summary['promptTokens'],
            completionTokens: $summary['completionTokens'],
            failures: $summary['failures'],
            averageDurationMs: $this->usage->averageDurationMs($organizationId, $period),
            costByProvider: $this->usage->costByProvider($organizationId, $period),
            costByPurpose: $this->usage->costByPurpose($organizationId, $period),
            callsByTier: $this->usage->callsByTier($organizationId, $period),
            durationByTier: $this->usage->durationByTier($organizationId, $period),
            messagesTotal: $stats['total'],
            messagesByTier: $stats['byTier'],
            averageProcessingMs: $stats['averageDurationMs'],
            deferredMessages: $this->deferredMessages(),
        );
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
     * Correos que están esperando presupuesto de IA ahora mismo.
     */
    private function deferredMessages(): int
    {
        $states = $this->messages->countByState();

        return $states[MessageProcessingState::DEFERRED->value] ?? 0;
    }

    /**
     * Identificador de la organización activa, si la hay.
     */
    private function organizationId(): ?Uuid
    {
        return $this->tenantContext->getOrganizationId();
    }

    /**
     * Un informe sin datos, para cuando no hay organización activa.
     */
    private function emptyReport(AiBudgetStatus $budget): AiCostReport
    {
        return new AiCostReport(
            budget: $budget,
            totalCostMicro: 0,
            calls: 0,
            promptTokens: 0,
            completionTokens: 0,
            failures: 0,
            averageDurationMs: 0,
            costByProvider: [],
            costByPurpose: [],
            callsByTier: [],
            durationByTier: [],
            messagesTotal: 0,
            messagesByTier: [],
            averageProcessingMs: 0,
        );
    }
}
