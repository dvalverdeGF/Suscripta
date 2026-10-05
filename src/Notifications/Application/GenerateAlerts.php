<?php

declare(strict_types=1);

namespace App\Notifications\Application;

use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Notifications\Application\Dto\AlertCandidate;
use App\Notifications\Application\Dto\AlertGenerationResult;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Repository\AlertRepositoryInterface;
use App\Notifications\Domain\Service\AlertRules;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Services\Domain\Service\ServiceCostCalculator;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use DateTimeImmutable;

use function max;
use function sprintf;

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
        private ServiceRepositoryInterface $services,
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

            foreach ($this->alerts->findOpenByType($type) as $open) {
                if (isset($keep[$open->getId()->toRfc4122()])) {
                    continue;
                }

                $open->resolve($now);
                ++$resolved;
            }
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
     * @return array<string, list<AlertCandidate>> clave = valor del tipo de aviso
     */
    private function collectCandidates(DateTimeImmutable $now): array
    {
        $candidates = [];

        foreach ($this->services->findForOrganization() as $service) {
            if (!$service->getStatus()->countsTowardsRecurringCost()) {
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

        return $candidates;
    }

    /**
     * @return list<AlertCandidate>
     */
    private function candidatesForService(Service $service, DateTimeImmutable $now): array
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

    private function chargeCandidate(Service $service, DateTimeImmutable $now): ?AlertCandidate
    {
        $nextChargeAt = $service->getNextChargeAt();

        if (null === $nextChargeAt || !$service->getBillingPeriod()->isRecurring()) {
            return null;
        }

        $days = $this->daysUntil($now, $nextChargeAt);

        if ($days < 0 || $days > AlertRules::CHARGE_LEAD_DAYS) {
            return null;
        }

        $amount = $service->getCurrentAmount();

        return new AlertCandidate(
            type: AlertType::UPCOMING_CHARGE,
            severity: AlertRules::chargeSeverity($days),
            title: sprintf('Cobro próximo: %s', $service->getName()),
            message: null === $amount
                ? sprintf('%s se cobra el %s (%s).', $service->getName(), $this->formatDate($nextChargeAt), $this->formatDays($days))
                : sprintf('%s te cobrará %s el %s (%s).', $service->getName(), $amount->format(), $this->formatDate($nextChargeAt), $this->formatDays($days)),
            dedupKey: Alert::buildDedupKey(AlertType::UPCOMING_CHARGE, $service->getId(), $nextChargeAt),
            dueAt: $nextChargeAt,
            serviceId: $service->getId(),
            metadata: [
                'daysUntil' => $days,
                'amount' => $amount?->format(),
                'currency' => $service->getCurrency()->value,
            ],
        );
    }

    private function noticeCandidate(Service $service, DateTimeImmutable $now): ?AlertCandidate
    {
        $deadline = $service->getNoticeDeadline();

        if (null === $deadline || !$service->isAutoRenews()) {
            return null;
        }

        $days = $this->daysUntil($now, $deadline);

        if ($days < 0 || $days > AlertRules::noticeLeadDays($service->getNoticePeriodDays() ?? 0)) {
            return null;
        }

        $renewalAt = $service->getRenewalAt();

        return new AlertCandidate(
            type: AlertType::NOTICE_DEADLINE,
            severity: AlertRules::renewalSeverity($days),
            title: sprintf('Se acaba el plazo para no renovar %s', $service->getName()),
            message: null === $renewalAt
                ? sprintf('Tienes hasta el %s para avisar de que no quieres renovar %s.', $this->formatDate($deadline), $service->getName())
                : sprintf(
                    'Tienes hasta el %s para avisar de que no quieres renovar %s. Se renueva el %s.',
                    $this->formatDate($deadline),
                    $service->getName(),
                    $this->formatDate($renewalAt),
                ),
            dedupKey: Alert::buildDedupKey(AlertType::NOTICE_DEADLINE, $service->getId(), $deadline),
            dueAt: $deadline,
            serviceId: $service->getId(),
            metadata: [
                'daysUntil' => $days,
                'noticePeriodDays' => $service->getNoticePeriodDays(),
                'renewalAt' => $renewalAt?->format('Y-m-d'),
            ],
        );
    }

    private function renewalCandidate(Service $service, DateTimeImmutable $now): ?AlertCandidate
    {
        $renewalAt = $service->getRenewalAt();

        if (null === $renewalAt || !$service->isAutoRenews()) {
            return null;
        }

        $annual = $this->isAnnual($service);
        $lead = $annual ? AlertRules::ANNUAL_RENEWAL_LEAD_DAYS : AlertRules::RENEWAL_LEAD_DAYS;
        $days = $this->daysUntil($now, $renewalAt);

        if ($days < 0 || $days > $lead) {
            return null;
        }

        $type = $annual ? AlertType::ANNUAL_RENEWAL : AlertType::UPCOMING_RENEWAL;
        $amount = $service->getCurrentAmount();

        return new AlertCandidate(
            type: $type,
            severity: AlertRules::renewalSeverity($days),
            title: $annual
                ? sprintf('Renovación anual de %s', $service->getName())
                : sprintf('%s se renueva pronto', $service->getName()),
            message: null === $amount
                ? sprintf('%s se renueva el %s (%s).', $service->getName(), $this->formatDate($renewalAt), $this->formatDays($days))
                : sprintf(
                    '%s se renueva el %s (%s) por %s.',
                    $service->getName(),
                    $this->formatDate($renewalAt),
                    $this->formatDays($days),
                    $amount->format(),
                ),
            dedupKey: Alert::buildDedupKey($type, $service->getId(), $renewalAt),
            dueAt: $renewalAt,
            serviceId: $service->getId(),
            metadata: [
                'daysUntil' => $days,
                'annual' => $annual,
                'amount' => $amount?->format(),
                'currency' => $service->getCurrency()->value,
            ],
        );
    }

    private function commitmentCandidate(Service $service, DateTimeImmutable $now): ?AlertCandidate
    {
        $commitmentEndAt = $service->getCommitmentEndAt();

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
            title: sprintf('Termina el compromiso de %s', $service->getName()),
            message: sprintf(
                'El compromiso de %s termina el %s (%s). A partir de ahí podrás cancelar sin coste.',
                $service->getName(),
                $this->formatDate($commitmentEndAt),
                $this->formatDays($days),
            ),
            dedupKey: Alert::buildDedupKey(AlertType::COMMITMENT_ENDING, $service->getId(), $commitmentEndAt),
            dueAt: $commitmentEndAt,
            serviceId: $service->getId(),
            metadata: ['daysUntil' => $days],
        );
    }

    private function priceIncreaseCandidate(Service $service): ?AlertCandidate
    {
        $ratio = $this->costs->priceChangeRatio($service);

        if (!AlertRules::isNoteworthyIncrease($ratio)) {
            return null;
        }

        $current = $service->getCurrentPrice();

        if (null === $current) {
            return null;
        }

        $validFrom = $current->getValidFrom();
        $percent = (int) round(max(0.0, $ratio ?? 0.0) * 100);

        return new AlertCandidate(
            type: AlertType::PRICE_INCREASE,
            severity: AlertSeverity::WARNING,
            title: sprintf('%s ha subido de precio', $service->getName()),
            message: sprintf(
                '%s cuesta ahora %s, un %d %% más que antes.',
                $service->getName(),
                $current->getAmount()->format(),
                $percent,
            ),
            dedupKey: Alert::buildDedupKey(AlertType::PRICE_INCREASE, $service->getId(), $validFrom),
            dueAt: $validFrom,
            serviceId: $service->getId(),
            metadata: [
                'ratio' => $ratio,
                'percent' => $percent,
                'amount' => $current->getAmount()->format(),
                'currency' => $service->getCurrency()->value,
            ],
        );
    }

    /**
     * ¿La periodicidad es anual? Se mira la periodicidad y no el precio: un
     * servicio anual sin precio registrado sigue siendo anual.
     */
    private function isAnnual(Service $service): bool
    {
        return 12 === $service->getBillingPeriod()->months() && 1 === $service->getBillingIntervalCount();
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
