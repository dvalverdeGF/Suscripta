<?php

declare(strict_types=1);

namespace App\Discovery\Application;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;

use function is_string;
use function sprintf;

use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * El usuario acepta una **inferencia** (D-59).
 *
 * Una inferencia no viene de un documento: es una sospecha deducida del
 * inventario. Por eso no se resuelve con el formulario de servicio —no hay
 * datos que revisar— sino con una decisión sobre la sospecha: «sí, es un
 * duplicado», «sí, ese cobro no llegó», «sí, ya no uso ese servicio».
 *
 * Qué significa "aceptar" depende del tipo, y en ningún caso se borra
 * historia:
 *
 * - **Duplicado**: se cancela el servicio más reciente y se deja constancia
 *   en el original. El histórico del servicio cancelado se conserva.
 * - **Cobro que no ha llegado**: se anota que se revisó y se hace avanzar la
 *   fecha del próximo cobro, para que la señal pueda volver a saltar en el
 *   siguiente periodo.
 * - **Servicio sin actividad**: se cancela el servicio, que es lo que el
 *   usuario ha dicho al aceptarlo.
 */
final readonly class ConfirmInference
{
    /**
     * Tope de periodos que se avanzan al recalcular un cobro que lleva
     * mucho tiempo atrasado. Cubre con holgura cualquier servicio real.
     */
    private const int MAX_PERIODS = 200;

    public function __construct(
        private DiscoveryRepositoryInterface $discoveries,
        private ServiceRepositoryInterface $services,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
    ) {
    }

    public function __invoke(Discovery $discovery, ?Uuid $actorUserId = null): ?Service
    {
        $this->tenantContext->requireOrganizationId();

        if (!$discovery->getStatus()->isPending()) {
            throw new InvalidArgumentException(sprintf('La propuesta ya está %s.', $discovery->getStatus()->label()));
        }

        if (!$discovery->getType()->isInference()) {
            throw new InvalidArgumentException('Esta propuesta no es una inferencia.');
        }

        $service = match ($discovery->getType()) {
            DiscoveryType::DUPLICATE => $this->resolveDuplicate($discovery, $actorUserId),
            DiscoveryType::MISSING_CHARGE => $this->resolveMissingCharge($discovery, $actorUserId),
            DiscoveryType::DORMANT => $this->resolveDormant($discovery, $actorUserId),
            default => null,
        };

        $discovery->confirm(
            userId: $actorUserId ?? $this->tenantContext->requireOrganizationId(),
            at: $this->clock->now(),
            resultingServiceId: $service?->getId(),
        );

        $this->discoveries->save($discovery);

        $this->auditLogger->log(
            action: AuditAction::DISCOVERY_CONFIRMED,
            targetType: 'discovery',
            targetId: $discovery->getId()->toRfc4122(),
            metadata: [
                'type' => $discovery->getType()->value,
                'inference' => true,
                'serviceId' => $service?->getId()->toRfc4122(),
            ],
            actorUserId: $actorUserId,
        );

        return $service;
    }

    /**
     * El usuario confirma que dos servicios son el mismo: se cancela el más
     * reciente y el original se queda con la historia.
     */
    private function resolveDuplicate(Discovery $discovery, ?Uuid $actorUserId): ?Service
    {
        $proposed = $discovery->getProposedData();
        $originalId = $discovery->getMatchedServiceId();
        $duplicateId = $this->uuid($proposed['relatedServiceId'] ?? null);

        if (null === $originalId || null === $duplicateId) {
            return null;
        }

        $original = $this->services->find($originalId);
        $duplicate = $this->services->find($duplicateId);

        if (null === $original || null === $duplicate) {
            return $original;
        }

        $at = $this->clock->now();

        $duplicate->cancel($at, $actorUserId);
        $duplicate->recordEvent(
            ServiceEventType::NOTE_ADDED,
            $actorUserId,
            ['note' => sprintf('Cancelado por duplicado de «%s».', $original->getName())],
            $at,
        );

        $original->recordEvent(
            ServiceEventType::NOTE_ADDED,
            $actorUserId,
            ['note' => sprintf('Se ha cancelado «%s» por duplicado.', $duplicate->getName())],
            $at,
        );

        $this->services->save($original);
        $this->services->save($duplicate);

        return $original;
    }

    /**
     * El usuario reconoce que el cobro no llegó: se anota y se hace avanzar
     * el próximo cobro, de modo que la señal pueda volver a saltar.
     */
    private function resolveMissingCharge(Discovery $discovery, ?Uuid $actorUserId): ?Service
    {
        $serviceId = $discovery->getMatchedServiceId();

        if (null === $serviceId) {
            return null;
        }

        $service = $this->services->find($serviceId);

        if (null === $service) {
            return null;
        }

        $proposed = $discovery->getProposedData();
        $expectedAt = $this->date($proposed['expectedAt'] ?? null);
        $at = $this->clock->now();

        $service->recordEvent(
            ServiceEventType::NOTE_ADDED,
            $actorUserId,
            ['note' => sprintf(
                'Revisado: el cobro esperado para el %s no llegó.',
                null === $expectedAt ? 'servicio' : $expectedAt->format('d/m/Y'),
            )],
            $at,
        );

        if (null !== $expectedAt) {
            $service->setNextChargeAt($this->nextChargeAfter($service, $expectedAt, $at));
        }

        $this->services->save($service);

        return $service;
    }

    /**
     * El usuario confirma que ya no usa el servicio: se cancela.
     */
    private function resolveDormant(Discovery $discovery, ?Uuid $actorUserId): ?Service
    {
        $serviceId = $discovery->getMatchedServiceId();

        if (null === $serviceId) {
            return null;
        }

        $service = $this->services->find($serviceId);

        if (null === $service) {
            return null;
        }

        $at = $this->clock->now();
        $service->cancel($at, $actorUserId);
        $service->recordEvent(
            ServiceEventType::NOTE_ADDED,
            $actorUserId,
            ['note' => 'Cancelado por inactividad: no se detectaba actividad desde hace meses.'],
            $at,
        );

        $this->services->save($service);

        return $service;
    }

    /**
     * La siguiente fecha de cobro a partir de la que se esperaba.
     *
     * Se avanza un periodo cada vez hasta llegar al futuro: un servicio que
     * lleva medio año sin facturar tiene que saltar al siguiente cobro
     * plausible, no quedarse en una fecha que vuelve a estar en el pasado.
     */
    private function nextChargeAfter(Service $service, DateTimeImmutable $expectedAt, DateTimeImmutable $now): DateTimeImmutable
    {
        $next = Service::advance($expectedAt, $service->getBillingPeriod(), $service->getBillingIntervalCount());

        for ($i = 0; $i < self::MAX_PERIODS && $next < $now; ++$i) {
            $next = Service::advance($next, $service->getBillingPeriod(), $service->getBillingIntervalCount());
        }

        return $next;
    }

    private function uuid(mixed $value): ?Uuid
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return Uuid::fromString($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
