<?php

declare(strict_types=1);

namespace App\Services\Application;

use App\Services\Application\Dto\ServiceInput;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Da de alta un servicio en el inventario de la organización activa.
 *
 * El precio inicial, si lo hay, entra como primera fila del historial: nunca se
 * escribe un importe en la entidad `Service` (D-13).
 */
final readonly class CreateService
{
    public function __construct(
        private ServiceRepositoryInterface $services,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(ServiceInput $input, ?Uuid $actorUserId = null): Service
    {
        $organizationId = $this->tenantContext->requireOrganizationId();

        $service = new Service(
            organizationId: $organizationId,
            name: $input->name,
            currency: $input->currency,
            billingPeriod: $input->billingPeriod,
            source: $input->source,
        );

        $service->setProviderId($input->providerId);
        $service->setCategoryId($input->categoryId);
        $service->setPlanName($input->planName);
        $service->setStartedAt($input->startedAt);
        $service->setRenewalAt($input->renewalAt);
        $service->setNoticePeriodDays($input->noticePeriodDays);
        $service->setAutoRenews($input->autoRenews);
        $service->setCommitmentEndAt($input->commitmentEndAt);
        $service->setPaymentMethodLabel($input->paymentMethodLabel);
        $service->setNotes($input->notes);
        $service->setCreatedByUserId($actorUserId);

        if ($input->billingIntervalCount < 1) {
            throw new InvalidArgumentException('El intervalo de facturación debe ser al menos 1.');
        }

        $service->changeBillingPeriod($input->billingPeriod, $input->billingIntervalCount);

        if (null !== $input->amount) {
            $service->changePrice(
                amount: $input->amount,
                validFrom: $input->startedAt ?? new DateTimeImmutable(),
                source: $input->source,
            );
        }

        $service->setNextChargeAt($input->nextChargeAt ?? $service->getNextChargeAt());

        $service->recordEvent(ServiceEventType::CREATED, $actorUserId, [
            'name' => $service->getName(),
            'source' => $service->getSource()->value,
        ]);

        $this->services->save($service);

        $this->auditLogger->log(
            action: AuditAction::SERVICE_CREATED,
            targetType: 'service',
            targetId: $service->getId()->toRfc4122(),
            metadata: ['name' => $service->getName(), 'source' => $service->getSource()->value],
        );

        return $service;
    }
}
