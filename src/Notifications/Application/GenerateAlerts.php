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

use function is_numeric;
use function is_string;
use function max;
use function sprintf;

use Symfony\Component\Uid\Uuid;

use function trim;

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

            if (null !== $open->getDiscoveryId() && $this->discoveryIsPending($open->getDiscoveryId())) {
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
            $candidate = $this->candidateForDiscovery($discovery);

            if (null !== $candidate) {
                $candidates[$candidate->type->value][] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * Aviso que corresponde a una propuesta pendiente, si esa propuesta tiene
     * un tipo que genera aviso.
     *
     * Todos los avisos de propuesta son **por propuesta**: el aviso identifica
     * qué propuesta es, no solo que hay algo pendiente. Por eso su clave de
     * deduplicación lleva el identificador de la propuesta.
     */
    private function candidateForDiscovery(Discovery $discovery): ?AlertCandidate
    {
        return match ($discovery->getType()) {
            DiscoveryType::NEW_SERVICE => $this->newServiceCandidate($discovery),
            DiscoveryType::PRICE_CHANGE => $this->priceChangeCandidate($discovery),
            DiscoveryType::PLAN_CHANGE => $this->planChangeCandidate($discovery),
            DiscoveryType::DUPLICATE => $this->duplicateCandidate($discovery),
            DiscoveryType::MISSING_CHARGE => $this->missingChargeCandidate($discovery),
            DiscoveryType::DORMANT => $this->dormantCandidate($discovery),
            default => null,
        };
    }

    /**
     * Aviso de un cambio de precio deducido de una factura.
     */
    private function priceChangeCandidate(Discovery $discovery): AlertCandidate
    {
        $proposal = ProposedData::fromArray($discovery->getProposedData());
        $name = $proposal->name() ?? 'un servicio';
        $amount = $proposal->amount();

        return new AlertCandidate(
            type: AlertType::PRICE_CHANGE_DETECTED,
            severity: AlertSeverity::INFO,
            title: sprintf('Cambio de precio en %s', $name),
            message: null === $amount
                ? sprintf('La última factura de %s indica un importe nuevo. Confírmalo para actualizar el precio.', $name)
                : sprintf('La última factura de %s indica un importe de %s. Confírmalo para actualizar el precio.', $name, $amount->format()),
            dedupKey: Alert::buildDedupKey(AlertType::PRICE_CHANGE_DETECTED, null, null, $discovery->getId()),
            discoveryId: $discovery->getId(),
            metadata: [
                'serviceId' => $discovery->getMatchedServiceId()?->toRfc4122(),
                'amount' => $amount?->format(),
                'currency' => $proposal->currency()->value,
                'confidence' => $discovery->getConfidence()->value,
                'confidenceScore' => $discovery->getConfidenceScore(),
            ],
        );
    }

    /**
     * Aviso de un cambio de plan deducido de una factura.
     */
    private function planChangeCandidate(Discovery $discovery): AlertCandidate
    {
        $proposal = ProposedData::fromArray($discovery->getProposedData());
        $name = $proposal->name() ?? 'un servicio';
        $plan = $proposal->planName();
        $previousPlan = $this->text($discovery->getProposedData()['previousPlan'] ?? null);

        $detail = null === $plan
            ? 'un plan nuevo'
            : sprintf('el plan %s', $plan);

        $message = sprintf('La última factura de %s indica %s', $name, $detail);

        if (null !== $previousPlan) {
            $message = sprintf('La última factura de %s indica %s (antes: %s)', $name, $detail, $previousPlan);
        }

        return new AlertCandidate(
            type: AlertType::PLAN_CHANGE_DETECTED,
            severity: AlertSeverity::INFO,
            title: sprintf('Cambio de plan en %s', $name),
            message: $message.'. Confírmalo para actualizar el plan.',
            dedupKey: Alert::buildDedupKey(AlertType::PLAN_CHANGE_DETECTED, null, null, $discovery->getId()),
            discoveryId: $discovery->getId(),
            metadata: [
                'serviceId' => $discovery->getMatchedServiceId()?->toRfc4122(),
                'plan' => $plan,
                'previousPlan' => $previousPlan,
                'confidence' => $discovery->getConfidence()->value,
                'confidenceScore' => $discovery->getConfidenceScore(),
            ],
        );
    }

    /**
     * Aviso de un posible duplicado.
     *
     * Es una **sospecha**: el aviso lo dice así en el texto, porque la
     * diferencia entre dos servicios parecidos puede ser intencionada (una
     * cuenta personal y otra de empresa, por ejemplo).
     */
    private function duplicateCandidate(Discovery $discovery): AlertCandidate
    {
        $data = $discovery->getProposedData();
        $name = $this->text($data['serviceName'] ?? null) ?? 'un servicio';
        $related = $this->text($data['relatedServiceName'] ?? null);

        $message = null === $related
            ? sprintf('%s se parece a otro servicio que tienes registrado.', $name)
            : sprintf('%s se parece a %s, que también tienes registrado.', $name, $related);

        return new AlertCandidate(
            type: AlertType::POSSIBLE_DUPLICATE,
            severity: AlertSeverity::WARNING,
            title: sprintf('Posible duplicado: %s', $name),
            message: $message.' Revísalo: si son el mismo, te ahorras una cuota.',
            dedupKey: Alert::buildDedupKey(AlertType::POSSIBLE_DUPLICATE, null, null, $discovery->getId()),
            discoveryId: $discovery->getId(),
            metadata: [
                'serviceName' => $name,
                'relatedServiceName' => $related,
                'similarity' => $this->number($data['similarity'] ?? null),
                'confidence' => $discovery->getConfidence()->value,
                'confidenceScore' => $discovery->getConfidenceScore(),
            ],
        );
    }

    /**
     * Aviso de un cobro que no ha llegado.
     */
    private function missingChargeCandidate(Discovery $discovery): AlertCandidate
    {
        $data = $discovery->getProposedData();
        $name = $this->text($data['serviceName'] ?? null) ?? 'un servicio';
        $expectedAt = $this->text($data['expectedAt'] ?? null);

        $message = null === $expectedAt
            ? sprintf('Esperábamos un cobro de %s y no lo hemos visto.', $name)
            : sprintf('Esperábamos un cobro de %s el %s y no lo hemos visto.', $name, $expectedAt);

        return new AlertCandidate(
            type: AlertType::MISSING_CHARGE,
            severity: AlertSeverity::WARNING,
            title: sprintf('Cobro que no ha llegado: %s', $name),
            message: $message.' Puede que no se haya cobrado, o que la factura llegue más tarde.',
            dedupKey: Alert::buildDedupKey(AlertType::MISSING_CHARGE, null, null, $discovery->getId()),
            discoveryId: $discovery->getId(),
            metadata: [
                'serviceName' => $name,
                'expectedAt' => $expectedAt,
                'overdueDays' => $this->number($data['overdueDays'] ?? null),
                'confidence' => $discovery->getConfidence()->value,
                'confidenceScore' => $discovery->getConfidenceScore(),
            ],
        );
    }

    /**
     * Aviso de un servicio sin actividad.
     */
    private function dormantCandidate(Discovery $discovery): AlertCandidate
    {
        $data = $discovery->getProposedData();
        $name = $this->text($data['serviceName'] ?? null) ?? 'un servicio';
        $inactiveDays = $this->number($data['inactiveDays'] ?? null);

        $message = null === $inactiveDays
            ? sprintf('%s lleva mucho tiempo sin actividad.', $name)
            : sprintf('%s lleva %d días sin actividad.', $name, $inactiveDays);

        return new AlertCandidate(
            type: AlertType::DORMANT_SERVICE,
            severity: AlertSeverity::INFO,
            title: sprintf('%s lleva tiempo sin actividad', $name),
            message: $message.' Si ya no lo usas, cancelarlo te quita ruido del inventario.',
            dedupKey: Alert::buildDedupKey(AlertType::DORMANT_SERVICE, null, null, $discovery->getId()),
            discoveryId: $discovery->getId(),
            metadata: [
                'serviceName' => $name,
                'inactiveDays' => $inactiveDays,
                'confidence' => $discovery->getConfidence()->value,
                'confidenceScore' => $discovery->getConfidenceScore(),
            ],
        );
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private function number(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
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
