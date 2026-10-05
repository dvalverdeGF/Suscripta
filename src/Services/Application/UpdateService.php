<?php

declare(strict_types=1);

namespace App\Services\Application;

use App\Services\Application\Dto\ServiceInput;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use Symfony\Component\Uid\Uuid;

/**
 * Edita los datos de un servicio existente.
 *
 * El importe no se toca aquí: un cambio de precio tiene su propio caso de uso
 * porque genera historial y evento (D-13).
 */
final readonly class UpdateService
{
    public function __construct(
        private ServiceRepositoryInterface $services,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(Service $service, ServiceInput $input, ?Uuid $actorUserId = null): Service
    {
        $previousPlan = $service->getPlanName();

        $service->rename($input->name);
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
        $service->changeBillingPeriod($input->billingPeriod, $input->billingIntervalCount);

        if (null !== $input->nextChargeAt) {
            $service->setNextChargeAt($input->nextChargeAt);
        }

        if ($previousPlan !== $service->getPlanName()) {
            $service->recordEvent(ServiceEventType::PLAN_CHANGED, $actorUserId, [
                'from' => $previousPlan,
                'to' => $service->getPlanName(),
            ]);
        }

        $this->services->save($service);

        $this->auditLogger->log(
            action: AuditAction::SERVICE_UPDATED,
            targetType: 'service',
            targetId: $service->getId()->toRfc4122(),
            metadata: ['name' => $service->getName()],
        );

        return $service;
    }
}
