<?php

declare(strict_types=1);

namespace App\Observability\Application;

use App\Ai\Application\AiCostCalculator;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailSyncRunRepositoryInterface;
use App\Notifications\Domain\Repository\AlertRepositoryInterface;
use App\Observability\Application\Dto\AlertActivity;
use App\Observability\Application\Dto\ExtractionActivity;
use App\Observability\Application\Dto\ObservabilityReport;
use App\Observability\Application\Dto\SyncActivity;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\DateRange;
use Symfony\Component\Uid\Uuid;

/**
 * Construye el informe de observabilidad de producto (Fase 17).
 *
 * ## Qué es observabilidad aquí
 *
 * No es "ver si la aplicación está viva" (eso lo hace `/health`, que mira el
 * proceso y la base de datos). Es **ver si el pipeline está haciendo su
 * trabajo**: que entra el correo, que se procesa, que lo que sale son
 * servicios reales y que el coste de hacerlo está controlado.
 *
 * La diferencia es importante porque un servicio puede estar perfectamente
 * vivo y no estar haciendo nada útil: el buzón lleva una semana sin
 * sincronizar y nadie se entera porque no ha saltado ningún error.
 *
 * ## Por qué un servicio y no consultas sueltas en la vista
 *
 * El informe cruza cuatro fuentes que viven en cuatro tablas (sincronizaciones,
 * mensajes, uso de IA y alertas) y todas tienen que ver **el mismo periodo**.
 * Calcularlo en la plantilla significaría cuatro agregaciones en Twig, que es
 * donde no se agrega nada. Aquí se agrega en PHP y se pinta.
 *
 * ## El periodo lo elige quien mira
 *
 * El panel ofrece 24 horas, 7 días y 30 días. El motivo de que 24 horas esté
 * aparte es operativo: es el periodo que se mira cuando algo no aparece
 * ("he enviado la factura y no sale"), y 7 días es el que se mira para saber
 * si el sistema trabaja bien.
 */
final readonly class BuildObservabilityReport
{
    /** Periodos ofrecidos, en días. `null` = las últimas 24 horas. */
    private const PERIODS = [
        '24h' => 1,
        '7d' => 7,
        '30d' => 30,
    ];

    public function __construct(
        private EmailSyncRunRepositoryInterface $syncRuns,
        private EmailMessageRepositoryInterface $messages,
        private AlertRepositoryInterface $alerts,
        private AiCostCalculator $aiCosts,
        private TenantContext $tenantContext,
        private Clock $clock,
    ) {
    }

    /**
     * Informe del periodo pedido (`24h`, `7d` o `30d`).
     *
     * Un periodo desconocido cae en 7 días: es el más útil y no merece la
     * pena inventedar un error para el usuario que manipula la URL.
     */
    public function forPeriod(string $period): ObservabilityReport
    {
        $days = self::PERIODS[$period] ?? self::PERIODS['7d'];
        $now = $this->clock->now();

        return $this->build(
            new DateRange($now->modify('-'.$days.' days'), $now->modify('+1 second')),
        );
    }

    /**
     * Periodos disponibles, para el selector de la pantalla.
     *
     * @return array<string, string>
     */
    public static function availablePeriods(): array
    {
        return [
            '24h' => 'Últimas 24 horas',
            '7d' => 'Últimos 7 días',
            '30d' => 'Últimos 30 días',
        ];
    }

    /**
     * Informe de un rango concreto.
     */
    public function build(DateRange $period): ObservabilityReport
    {
        $organizationId = $this->tenantContext->getOrganizationId();

        return new ObservabilityReport(
            period: $period,
            sync: $this->syncActivity($organizationId, $period),
            extraction: $this->extractionActivity($organizationId, $period),
            ai: $this->aiCosts->report($period),
            alerts: $this->alertActivity(),
        );
    }

    /**
     * Actividad de sincronización.
     *
     * Sin organización activa no hay buzones que sincronizar: se devuelve una
     * actividad vacía en lugar de un error, para que la pantalla siga siendo
     * útil para el resto de secciones.
     */
    private function syncActivity(?Uuid $organizationId, DateRange $period): SyncActivity
    {
        if (null === $organizationId) {
            return new SyncActivity(0, 0, 0, 0, 0, 0, 0, 0);
        }

        $stats = $this->syncRuns->statsForOrganization($organizationId, $period);

        return new SyncActivity(
            runs: $stats['runs'],
            failed: $stats['failed'],
            seen: $stats['seen'],
            processed: $stats['processed'],
            skipped: $stats['skipped'],
            backfilled: $stats['backfilled'],
            discoveries: $stats['discoveries'],
            averageDurationMs: $stats['averageDurationMs'],
        );
    }

    /**
     * Actividad de extracción.
     */
    private function extractionActivity(?Uuid $organizationId, DateRange $period): ExtractionActivity
    {
        $states = $this->messages->countByState();
        $deferred = $states[MessageProcessingState::DEFERRED->value] ?? 0;

        if (null === $organizationId) {
            return new ExtractionActivity([], $states, 0, $deferred, 0);
        }

        $stats = $this->messages->extractionStats($organizationId, $period);

        return new ExtractionActivity(
            byTier: $stats['byTier'],
            byState: $states,
            messagesTotal: $stats['total'],
            deferred: $deferred,
            averageDurationMs: $stats['averageDurationMs'],
        );
    }

    /**
     * Actividad de avisos.
     */
    private function alertActivity(): AlertActivity
    {
        $openByType = [];

        foreach ($this->alerts->findOpen() as $alert) {
            $type = $alert->getType()->value;
            $openByType[$type] = ($openByType[$type] ?? 0) + 1;
        }

        return new AlertActivity(
            open: $this->alerts->countOpen(),
            byStatus: $this->alerts->countByStatus(),
            openByType: $openByType,
        );
    }
}
