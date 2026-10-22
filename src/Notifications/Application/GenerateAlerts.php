<?php

declare(strict_types=1);

namespace App\Notifications\Application;

use App\Discovery\Application\Dto\ProposedData;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Notifications\Application\Dto\AlertCandidate;
use App\Notifications\Application\Dto\AlertGenerationResult;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Repository\AlertRepositoryInterface;
use App\Notifications\Domain\Service\AlertRules;
use App\Services\Domain\Dto\ServiceView;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use App\Services\Domain\Service\ServiceCostCalculator;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use DateTimeImmutable;

use function max;
use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * Decide qué avisos debería tener abiertos la organización (ARCHITECTURE.md §4.7).
 *
 * Es **determinista e idempotente**: se puede ejecutar cada hora sin que el
 * usuario reciba el mismo aviso dos veces, porque cada aviso lleva una clave de
 * deduplicación derivada del tipo, el servicio y la fecha objetivo.
 *
 * El generador hace dos cosas, y la segunda es tan importante como la primera:
 *
 * 1. **Crea** los avisos que corresponden al estado actual.
 * 2. **Resuelve** los que ya no corresponden. Un aviso de cobro que se quedó
 *    abierto porque el cobro ya pasó es peor que no tener aviso: enseña al
 *    usuario a ignorar la bandeja.
 *
 * No usa IA en ningún punto. Todos los avisos salen de fechas e importes que ya
 * están en la base de datos, así que el usuario siempre puede comprobar por qué
 * lo ha recibido.
 */
final readonly class GenerateAlerts
{
    public function __construct(
        private ServiceViewRepositoryInterface $services,
        private AlertRepositoryInterface $alerts,
        private DiscoveryRepositoryInterface $discoveries,
        private ServiceCostCalculator $costs,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
    ) {
    }

    public function __invoke(?DateTimeImmutable $now = null): AlertGenerationResult
    {
        $organizationId = $this->tenantContext->requireOrganizationId();
        $now ??= $this->clock->today();

        $candidates = $this->collectCandidates($now);

        $created = 0;
        $resolved = 0;
        $unchanged = 0;

        foreach (AlertRules::generatedTypes() as $type) {
            $keep = [];

            foreach ($candidates[$type->value] ?? [] as $candidate) {
                $existing = $this->alerts->findByDedupKey($candidate->dedupKey);

                if (null !== $existing) {
                    // Ya existe. No se reabre aunque el usuario lo haya
                    // descartado: descartar es una decisión, no un olvido.
                    $keep[$existing->getId()->toRfc4122()] = true;
                    ++$unchanged;

                    continue;
                }

                $alert = new Alert(
                    organizationId: $organizationId,
                    type: $candidate->type,
                    severity: $candidate->severity,
                    title: $candidate->title,
                    message: $candidate->message,
                    dedupKey: $candidate->dedupKey,
                    dueAt: $candidate->dueAt,
                    serviceId: $candidate->serviceId,
                    discoveryId: $candidate->discoveryId,
                    createdAt: $now,
                );
                $alert->setMetadata($candidate->metadata);

                $this->alerts->save($alert, false);
                $keep[$alert->getId()->toRfc4122()] = true;
                ++$created;
            }

            $resolved += $this->resolveStaleAlerts($type, $keep, $now);
        }

        if ($created > 0 || $resolved > 0) {
            $this->alerts->flush();
        }

        if ($created > 0) {
            $this->auditLogger->log(
                action: AuditAction::ALERT_GENERATED,
                targetType: 'organization',
                targetId: $organizationId->toRfc4122(),
                metadata: ['created' => $created, 'resolved' => $resolved],
            );
        }

        return new AlertGenerationResult(
            created: $created,
            resolved: $resolved,
            unchanged: $unchanged,
            open: $this->alerts->countOpen(),
        );
    }

    /**
     * Cierra los avisos que ya no describen la realidad.
     *
     * La regla general es «si no hay candidato para este aviso, la situación
     * que lo motivó ya no se da». Para los avisos que cuelgan de una propuesta
     * hay que ser más cuidadoso: que la propuesta no haya salido en el barrido
     * no significa que haya dejado de estar pendiente, así que se consulta su
     * estado real antes de cerrarla.
     *
     * @param array<string, true> $keep identificadores de los avisos que se
     *                                  quedan abiertos
     *
     * @return int número de avisos resueltos
     */
    private function resolveStaleAlerts(AlertType $type, array $keep, DateTimeImmutable $now): int
    {
        $resolved = 0;

        foreach ($this->alerts->findOpenByType($type) as $open) {
            if (isset($keep[$open->getId()->toRfc4122()])) {
                continue;
            }

            if (AlertType::NEW_SERVICE_DETECTED === $type && $this->discoveryIsPending($open->getDiscoveryId())) {
                continue;
            }

            $open->resolve($now);
            ++$resolved;
        }

        return $resolved;
    }

    /**
     * ¿La propuesta que motivó el aviso sigue esperando una decisión?
     *
     * Si ya no existe o ya está resuelta, el aviso se puede cerrar.
     */
    private function discoveryIsPending(?Uuid $discoveryId): bool
    {
        if (null === $discoveryId) {
            return false;
        }

        $discovery = $this->discoveries->find($discoveryId);

        return null !== $discovery && $discovery->getStatus()->isPending();
    }

    /**
     * @return array<string, list<AlertCandidate>> clave = valor del tipo de aviso
     */
    private function collectCandidates(DateTimeImmutable $now): array
    {
        $candidates = [];

        foreach ($this->services->findAll() as $service) {
            if (!$service->status->countsTowardsRecurringCost()) {
                continue;
            }

            foreach ($this->candidatesForService($service, $now) as $candidate) {
                $candidates[$candidate->type->value][] = $candidate;
            }
        }

        $pending = $this->discoveries->countPending();

        if ($pending > 0) {
            $candidates[AlertType::DISCOVERY_PENDING->value][] = new AlertCandidate(
                type: AlertType::DISCOVERY_PENDING,
                severity: AlertSeverity::INFO,
                title: 1 === $pending ? 'Tienes una propuesta por revisar' : sprintf('Tienes %d propuestas por revisar', $pending),
                message: 'Hemos encontrado servicios en tu correo de facturación que esperan tu confirmación.',
                dedupKey: Alert::buildDedupKey(AlertType::DISCOVERY_PENDING, null, null),
                metadata: ['pending' => $pending],
            );
        }

        foreach ($this->discoveries->findPending(AlertRules::PENDING_DISCOVERY_SCAN_LIMIT) as $discovery) {
            if (DiscoveryType::NEW_SERVICE !== $discovery->getType()) {
                continue;
            }

            $candidates[AlertType::NEW_SERVICE_DETECTED->value][] = $this->newServiceCandidate($discovery);
        }

        return $candidates;
    }

    /**
     * Aviso de un servicio nuevo encontrado en el correo.
     *
     * Es **por propuesta**: el aviso identifica qué servicio se ha detectado,
     * no solo que hay algo pendiente. Por eso su clave de deduplicación lleva
     * el identificador de la propuesta y no el del servicio, que todavía no
     * existe.
     */
    private function newServiceCandidate(Discovery $discovery): AlertCandidate
    {
        $proposal = ProposedData::fromArray($discovery->getProposedData());
        $name = $proposal->name() ?? 'un servicio nuevo';
        $amount = $proposal->amount();

        return new AlertCandidate(
            type: AlertType::NEW_SERVICE_DETECTED,
            severity: AlertSeverity::INFO,
            title: sprintf('Nuevo servicio detectado: %s', $name),
            message: null === $amount
                ? sprintf('Hemos encontrado %s en tu correo y no lo tienes registrado. Confírmalo para empezar a controlarlo.', $name)
                : sprintf('Hemos encontrado %s (%s) en tu correo y no lo tienes registrado. Confírmalo para empezar a controlarlo.', $name, $amount->format()),
            dedupKey: Alert::buildDedupKey(AlertType::NEW_SERVICE_DETECTED, null, null, $discovery->getId()),
            discoveryId: $discovery->getId(),
            metadata: [
                'providerName' => $name,
                'amount' => $amount?->format(),
                'currency' => $proposal->currency()->value,
                'confidence' => $discovery->getConfidence()->value,
                'confidenceScore' => $discovery->getConfidenceScore(),
            ],
        );
    }

    /**
     * @return list<AlertCandidate>
     */
    private function candidatesForService(ServiceView $service, DateTimeImmutable $now): array
    {
        $candidates = [];

        $charge = $this->chargeCandidate($service, $now);

        if (null !== $charge) {
            $candidates[] = $charge;
        }

        $notice = $this->noticeCandidate($service, $now);

        if (null !== $notice) {
            $candidates[] = $notice;
        }

        $renewal = $this->renewalCandidate($service, $now);

        if (null !== $renewal) {
            $candidates[] = $renewal;
        }

        $commitment = $this->commitmentCandidate($service, $now);

        if (null !== $commitment) {
            $candidates[] = $commitment;
        }

        $increase = $this->priceIncreaseCandidate($service);

        if (null !== $increase) {
            $candidates[] = $increase;
        }

        return $candidates;
    }

    private function chargeCandidate(ServiceView $service, DateTimeImmutable $now): ?AlertCandidate
    {
        $nextChargeAt = $service->nextChargeAt;

        if (null === $nextChargeAt || !$service->billingPeriod->isRecurring()) {
            return null;
        }

        $days = $this->daysUntil($now, $nextChargeAt);

        if ($days < 0 || $days > AlertRules::CHARGE_LEAD_DAYS) {
            return null;
        }

        $amount = $service->currentAmount;

        return new AlertCandidate(
            type: AlertType::UPCOMING_CHARGE,
            severity: AlertRules::chargeSeverity($days),
            title: sprintf('Cobro próximo: %s', $service->name),
            message: null === $amount
                ? sprintf('%s se cobra el %s (%s).', $service->name, $this->formatDate($nextChargeAt), $this->formatDays($days))
                : sprintf('%s te cobrará %s el %s (%s).', $service->name, $amount->format(), $this->formatDate($nextChargeAt), $this->formatDays($days)),
            dedupKey: Alert::buildDedupKey(AlertType::UPCOMING_CHARGE, $service->id, $nextChargeAt),
            dueAt: $nextChargeAt,
            serviceId: $service->id,
            metadata: [
                'daysUntil' => $days,
                'amount' => $amount?->format(),
                'currency' => $service->currency->value,
            ],
        );
    }

    private function noticeCandidate(ServiceView $service, DateTimeImmutable $now): ?AlertCandidate
    {
        $deadline = $service->noticeDeadline;

        if (null === $deadline || !$service->autoRenews) {
            return null;
        }

        $days = $this->daysUntil($now, $deadline);

        if ($days < 0 || $days > AlertRules::noticeLeadDays($service->noticePeriodDays ?? 0)) {
            return null;
        }

        $renewalAt = $service->renewalAt;

        return new AlertCandidate(
            type: AlertType::NOTICE_DEADLINE,
            severity: AlertRules::renewalSeverity($days),
            title: sprintf('Se acaba el plazo para no renovar %s', $service->name),
            message: null === $renewalAt
                ? sprintf('Tienes hasta el %s para avisar de que no quieres renovar %s.', $this->formatDate($deadline), $service->name)
                : sprintf(
                    'Tienes hasta el %s para avisar de que no quieres renovar %s. Se renueva el %s.',
                    $this->formatDate($deadline),
                    $service->name,
                    $this->formatDate($renewalAt),
                ),
            dedupKey: Alert::buildDedupKey(AlertType::NOTICE_DEADLINE, $service->id, $deadline),
            dueAt: $deadline,
            serviceId: $service->id,
            metadata: [
                'daysUntil' => $days,
                'noticePeriodDays' => $service->noticePeriodDays,
                'renewalAt' => $renewalAt?->format('Y-m-d'),
            ],
        );
    }

    private function renewalCandidate(ServiceView $service, DateTimeImmutable $now): ?AlertCandidate
    {
        $renewalAt = $service->renewalAt;

        if (null === $renewalAt || !$service->autoRenews) {
            return null;
        }

        $annual = $this->isAnnual($service);
        $lead = $annual ? AlertRules::ANNUAL_RENEWAL_LEAD_DAYS : AlertRules::RENEWAL_LEAD_DAYS;
        $days = $this->daysUntil($now, $renewalAt);

        if ($days < 0 || $days > $lead) {
            return null;
        }

        $type = $annual ? AlertType::ANNUAL_RENEWAL : AlertType::UPCOMING_RENEWAL;
        $amount = $service->currentAmount;

        return new AlertCandidate(
            type: $type,
            severity: AlertRules::renewalSeverity($days),
            title: $annual
                ? sprintf('Renovación anual de %s', $service->name)
                : sprintf('%s se renueva pronto', $service->name),
            message: null === $amount
                ? sprintf('%s se renueva el %s (%s).', $service->name, $this->formatDate($renewalAt), $this->formatDays($days))
                : sprintf(
                    '%s se renueva el %s (%s) por %s.',
                    $service->name,
                    $this->formatDate($renewalAt),
                    $this->formatDays($days),
                    $amount->format(),
                ),
            dedupKey: Alert::buildDedupKey($type, $service->id, $renewalAt),
            dueAt: $renewalAt,
            serviceId: $service->id,
            metadata: [
                'daysUntil' => $days,
                'annual' => $annual,
                'amount' => $amount?->format(),
                'currency' => $service->currency->value,
            ],
        );
    }

    private function commitmentCandidate(ServiceView $service, DateTimeImmutable $now): ?AlertCandidate
    {
        $commitmentEndAt = $service->commitmentEndAt;

        if (null === $commitmentEndAt) {
            return null;
        }

        $days = $this->daysUntil($now, $commitmentEndAt);

        if ($days < 0 || $days > AlertRules::COMMITMENT_LEAD_DAYS) {
            return null;
        }

        return new AlertCandidate(
            type: AlertType::COMMITMENT_ENDING,
            severity: AlertSeverity::INFO,
            title: sprintf('Termina el compromiso de %s', $service->name),
            message: sprintf(
                'El compromiso de %s termina el %s (%s). A partir de ahí podrás cancelar sin coste.',
                $service->name,
                $this->formatDate($commitmentEndAt),
                $this->formatDays($days),
            ),
            dedupKey: Alert::buildDedupKey(AlertType::COMMITMENT_ENDING, $service->id, $commitmentEndAt),
            dueAt: $commitmentEndAt,
            serviceId: $service->id,
            metadata: ['daysUntil' => $days],
        );
    }

    private function priceIncreaseCandidate(ServiceView $service): ?AlertCandidate
    {
        $ratio = $this->costs->priceChangeRatio($service);

        if (!AlertRules::isNoteworthyIncrease($ratio)) {
            return null;
        }

        $current = $service->currentPrice;

        if (null === $current) {
            return null;
        }

        $validFrom = $current->validFrom;
        $percent = (int) round(max(0.0, $ratio ?? 0.0) * 100);

        return new AlertCandidate(
            type: AlertType::PRICE_INCREASE,
            severity: AlertSeverity::WARNING,
            title: sprintf('%s ha subido de precio', $service->name),
            message: sprintf(
                '%s cuesta ahora %s, un %d %% más que antes.',
                $service->name,
                $current->amount->format(),
                $percent,
            ),
            dedupKey: Alert::buildDedupKey(AlertType::PRICE_INCREASE, $service->id, $validFrom),
            dueAt: $validFrom,
            serviceId: $service->id,
            metadata: [
                'ratio' => $ratio,
                'percent' => $percent,
                'amount' => $current->amount->format(),
                'currency' => $service->currency->value,
            ],
        );
    }

    /**
     * ¿La periodicidad es anual? Se mira la periodicidad y no el precio: un
     * servicio anual sin precio registrado sigue siendo anual.
     */
    private function isAnnual(ServiceView $service): bool
    {
        return 12 === $service->billingPeriod->months() && 1 === $service->billingIntervalCount;
    }

    /**
     * Días naturales entre dos fechas, con signo.
     *
     * Se normalizan a medianoche para que un cobro «mañana a las 09:00» cuente
     * como un día y no como cero.
     */
    private function daysUntil(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $start = $from->setTime(0, 0);
        $end = $to->setTime(0, 0);
        $days = (int) $start->diff($end)->days;

        return $end < $start ? -$days : $days;
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->format('d/m/Y');
    }

    private function formatDays(int $days): string
    {
        return match (true) {
            0 === $days => 'hoy',
            1 === $days => 'mañana',
            default => sprintf('en %d días', $days),
        };
    }
}
