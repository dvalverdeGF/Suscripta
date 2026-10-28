<?php

declare(strict_types=1);

namespace App\Discovery\Application;

use App\Discovery\Application\Dto\DiscoveryInput;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Processing\Application\Knowledge\LearnFromConfirmation;
use App\Services\Application\CreateService;
use App\Services\Application\Dto\ServiceInput;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Webhooks\Application\WebhookNotifier;
use App\Webhooks\Domain\Enum\WebhookEvent;

use function is_scalar;
use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * El usuario acepta una propuesta (ARCHITECTURE.md §13.11).
 *
 * Es el momento en que un descubrimiento se convierte en negocio. Hay dos
 * caminos y la diferencia importa:
 *
 * - **Con servicio asociado**: el documento pertenece a algo que ya existe, así
 *   que se registra el precio nuevo cerrando el anterior. Nunca se muta el
 *   historial (D-13).
 * - **Sin servicio asociado**: se crea el servicio con origen
 *   `email_discovery`, para que el usuario pueda distinguir de un vistazo lo
 *   que ha dado de alta a mano de lo que ha encontrado el sistema.
 *
 * En ambos casos se guarda lo que el usuario ha confirmado, no lo que el
 * sistema había propuesto: la propuesta original se conserva en
 * `Discovery.proposedData` para poder medir la precisión del pipeline.
 *
 * Al final anuncia `proposal.confirmed` (Fase 20). El evento dice **qué** se ha
 * confirmado y **en qué servicio** ha acabado, que es lo que un integrador no
 * puede deducir por su cuenta: la propuesta desaparece de la bandeja al
 * confirmarse.
 */
final readonly class ConfirmDiscovery
{
    public function __construct(
        private DiscoveryRepositoryInterface $discoveries,
        private ServiceRepositoryInterface $services,
        private CreateService $createService,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
        private LearnFromConfirmation $learnFromConfirmation,
        private ClearSourceExcerpt $clearSourceExcerpt,
        /**
         * Opcional para que las pruebas unitarias que no ejercitan webhooks no
         * tengan que montar la cola. En la aplicación siempre llega inyectado.
         */
        private ?WebhookNotifier $webhooks = null,
    ) {
    }

    public function __invoke(Discovery $discovery, DiscoveryInput $input, ?Uuid $actorUserId = null): Service
    {
        $this->tenantContext->requireOrganizationId();

        if (!$discovery->getStatus()->isPending()) {
            throw new InvalidArgumentException(sprintf('La propuesta ya está %s.', $discovery->getStatus()->label()));
        }

        $service = $this->resolveService($discovery, $input, $actorUserId);

        $discovery->confirm(
            userId: $actorUserId ?? $this->tenantContext->requireOrganizationId(),
            at: $this->clock->now(),
            resultingServiceId: $service->getId(),
            edited: $this->wasEdited($discovery, $input),
        );

        $this->discoveries->save($discovery);

        // El extracto del cuerpo solo vive mientras hay una propuesta
        // pendiente: al confirmar, el correo deja de hacer falta.
        ($this->clearSourceExcerpt)($discovery);

        // ── Fase 13: el buzón aprende de la confirmación ───────────────────
        // Lo que el usuario ha confirmado es la mejor información que existe
        // sobre ese remitente: su periodicidad y su proveedor son ciertos.
        ($this->learnFromConfirmation)($discovery, $input);

        $this->auditLogger->log(
            action: AuditAction::DISCOVERY_CONFIRMED,
            targetType: 'discovery',
            targetId: $discovery->getId()->toRfc4122(),
            metadata: [
                'type' => $discovery->getType()->value,
                'serviceId' => $service->getId()->toRfc4122(),
                'matchScore' => $discovery->getMatchScore(),
            ],
            actorUserId: $actorUserId,
        );

        $this->webhooks?->notify($discovery->getOrganizationId(), WebhookEvent::PROPOSAL_CONFIRMED, [
            'discoveryId' => $discovery->getId()->toRfc4122(),
            'type' => $discovery->getType()->value,
            'serviceId' => $service->getId()->toRfc4122(),
            'serviceName' => $service->getName(),
            'matchedExistingService' => null !== $discovery->getMatchedServiceId(),
        ]);

        return $service;
    }

    private function resolveService(Discovery $discovery, DiscoveryInput $input, ?Uuid $actorUserId): Service
    {
        $matchedId = $discovery->getMatchedServiceId();

        if (null !== $matchedId) {
            $service = $this->services->find($matchedId);

            if (null !== $service) {
                return $this->applyToExisting($service, $input, $actorUserId);
            }
        }

        return ($this->createService)(new ServiceInput(
            name: $input->name,
            currency: $input->currency,
            billingPeriod: $input->billingPeriod,
            billingIntervalCount: $input->billingIntervalCount,
            amount: $input->amount,
            providerId: $input->providerId,
            categoryId: $input->categoryId,
            planName: $input->planName,
            startedAt: $input->startedAt,
            nextChargeAt: $input->nextChargeAt,
            renewalAt: $input->renewalAt,
            noticePeriodDays: $input->noticePeriodDays,
            autoRenews: $input->autoRenews,
            notes: $input->notes,
            source: ServiceSource::EMAIL_DISCOVERY,
        ), $actorUserId);
    }

    private function applyToExisting(Service $service, DiscoveryInput $input, ?Uuid $actorUserId): Service
    {
        if (null !== $input->amount && $input->amount->currency === $service->getCurrency()) {
            $changed = $service->changePrice(
                amount: $input->amount,
                validFrom: $this->clock->now(),
                source: ServiceSource::EMAIL_DISCOVERY,
                note: 'Detectado en el correo de facturación.',
            );

            if ($changed) {
                $service->recordEvent(ServiceEventType::PRICE_CHANGED, $actorUserId, [
                    'amount' => $input->amount->format(),
                    'source' => ServiceSource::EMAIL_DISCOVERY->value,
                ]);

                // `charge.detected` se anuncia **aquí** y no al final del caso
                // de uso: es el único punto donde se sabe que el precio ha
                // cambiado de verdad. Un importe igual al vigente no es un
                // cobro nuevo, y anunciarlo obligaría al integrador a
                // deduplicar lo que Suscripta ya sabe.
                $this->webhooks?->notify($service->getOrganizationId(), WebhookEvent::CHARGE_DETECTED, [
                    'serviceId' => $service->getId()->toRfc4122(),
                    'serviceName' => $service->getName(),
                    'amountMinor' => $input->amount->amountMinor,
                    'currency' => $input->amount->currency->value,
                    'billingPeriod' => $service->getBillingPeriod()->value,
                    'source' => ServiceSource::EMAIL_DISCOVERY->value,
                ]);
            }
        }

        if (null !== $input->planName && $input->planName !== $service->getPlanName()) {
            $service->setPlanName($input->planName);
            $service->recordEvent(ServiceEventType::PLAN_CHANGED, $actorUserId, ['plan' => $input->planName]);
        }

        if (null !== $input->renewalAt) {
            $service->setRenewalAt($input->renewalAt);
        }

        $this->services->save($service);

        return $service;
    }

    /**
     * ¿El usuario ha corregido la propuesta? Se guarda para poder medir la
     * precisión real del pipeline en lugar de suponerla.
     */
    private function wasEdited(Discovery $discovery, DiscoveryInput $input): bool
    {
        $proposed = $discovery->getProposedData();
        $proposedAmount = $proposed['amountMinor'] ?? null;
        $inputAmount = $input->amount?->amountMinor;

        return ($proposed['providerName'] ?? null) !== $input->name
            || (is_scalar($proposedAmount) ? (string) $proposedAmount : '') !== (null === $inputAmount ? '' : (string) $inputAmount)
            || ($proposed['billingPeriod'] ?? null) !== $input->billingPeriod->value;
    }
}
