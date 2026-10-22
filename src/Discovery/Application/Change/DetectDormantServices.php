<?php

declare(strict_types=1);

namespace App\Discovery\Application\Change;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Documents\Domain\Repository\InvoiceRepositoryInterface;
use App\Services\Domain\Dto\ServiceView;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use DateTimeImmutable;

use function floor;
use function in_array;
use function intdiv;
use function min;
use function sprintf;

/**
 * Detecta los servicios que llevan demasiado tiempo sin dar señales de vida
 * (D-59).
 *
 * Un servicio que no se renueva, no cobra y no recibe documentos lleva meses
 * ocupando un hueco en el inventario y, casi seguro, pagando de más. Es una
 * **inferencia**: el sistema no sabe que el usuario se ha olvidado del
 * servicio, solo sabe que lleva tiempo sin moverse. Por eso no cancela nada
 * por su cuenta: lo sugiere.
 *
 * No todo movimiento del servicio cuenta como señal de vida. Que el usuario
 * edite una nota o pause el servicio no dice nada sobre si el negocio sigue
 * vivo; lo que lo dice es que **haya entrado dinero o actividad real**: una
 * factura, una renovación, un documento nuevo o una periodicidad detectada.
 */
final readonly class DetectDormantServices
{
    /**
     * Los eventos que de verdad hablan de la vida del servicio. Los demás
     * —pausar, reanudar, editar el precio, añadir una nota— son decisiones de
     * gestión, no actividad del negocio.
     */
    private const array ACTIVITY_EVENTS = [
        ServiceEventType::RENEWED,
        ServiceEventType::RECURRENCE_DETECTED,
        ServiceEventType::DOCUMENT_ADDED,
    ];

    /**
     * Fuerza de la señal al detectarse la inactividad: 60 puntos y uno más
     * cada cuatro días de inactividad, hasta 100.
     */
    private const int BASE_SCORE = 60;

    public function __construct(
        private ServiceViewRepositoryInterface $services,
        private InvoiceRepositoryInterface $invoices,
        private DiscoveryRepositoryInterface $discoveries,
        private ChangeSettings $settings,
        private TenantContext $tenantContext,
        private Clock $clock,
    ) {
    }

    /**
     * @return list<Discovery>
     */
    public function detect(): array
    {
        $now = $this->clock->now();
        $lastCharges = $this->invoices->findLastIssuedAtByService();
        $proposals = [];

        foreach ($this->services->findAll(ServiceFilters::none()) as $service) {
            if (ServiceStatus::ACTIVE !== $service->status) {
                continue;
            }

            $lastSignalAt = $this->lastSignalAt($service, $lastCharges);

            $inactiveDays = (int) floor(($now->getTimestamp() - $lastSignalAt->getTimestamp()) / 86400);

            if ($inactiveDays <= $this->settings->dormantServiceDays) {
                continue;
            }

            $proposal = $this->propose($service, $lastSignalAt, $inactiveDays);

            if (null !== $proposal) {
                $proposals[] = $proposal;
            }
        }

        return $proposals;
    }

    /**
     * @param array<string, DateTimeImmutable> $lastCharges
     */
    private function lastSignalAt(ServiceView $service, array $lastCharges): DateTimeImmutable
    {
        $key = $service->id->toRfc4122();
        $last = $lastCharges[$key] ?? null;

        foreach ($service->events as $event) {
            if (!in_array($event->type, self::ACTIVITY_EVENTS, true)) {
                continue;
            }

            if (null === $last || $event->occurredAt > $last) {
                $last = $event->occurredAt;
            }
        }

        // Si no hay ninguna señal, el servicio está inactivo desde que se dio
        // de alta. Sin esa referencia el detector no podría decir nada.
        if (null === $last) {
            $last = $service->startedAt ?? $service->createdAt;
        }

        return $last;
    }

    private function propose(ServiceView $service, DateTimeImmutable $lastSignalAt, int $inactiveDays): ?Discovery
    {
        $key = $service->id->toRfc4122();

        $proposedData = [
            'serviceId' => $key,
            'inactiveSince' => $lastSignalAt->format('Y-m-d'),
            'serviceName' => $service->name,
            'planName' => $service->planName,
            'currency' => $service->currency->value,
            'amountMinor' => $service->currentAmount?->amountMinor,
            'inactiveDays' => $inactiveDays,
            'lastSignalAt' => $lastSignalAt->format('Y-m-d'),
        ];

        $dedupKey = Discovery::buildDedupKey(DiscoveryType::DORMANT, $proposedData);

        // Una sugerencia por episodio de inactividad: si el usuario ya la ha
        // visto, no se repite hasta que el servicio vuelva a dar señales.
        if (null !== $this->discoveries->findByDedupKey($dedupKey)) {
            return null;
        }

        $score = min(100, self::BASE_SCORE + intdiv($inactiveDays, 4));

        $reasons = [
            [
                'signal' => 'Sin actividad desde hace tiempo',
                'weight' => $score,
                'detail' => sprintf(
                    'Sin facturas, renovaciones ni documentos desde el %s',
                    $lastSignalAt->format('d/m/Y'),
                ),
            ],
        ];

        $discovery = new Discovery(
            organizationId: $this->tenantContext->requireOrganizationId(),
            type: DiscoveryType::DORMANT,
            detectedAt: $this->clock->now(),
            proposedData: $proposedData,
        );

        $discovery->applyMatch($score, $reasons, $service->id);
        $discovery->applyConfidence($score);
        $discovery->markAsInference();

        $this->discoveries->save($discovery);

        return $discovery;
    }
}
