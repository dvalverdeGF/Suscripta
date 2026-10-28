<?php

declare(strict_types=1);

namespace App\Subscriptions\Application;

use App\Shared\Application\TenantContext;
use App\Subscriptions\Application\Dto\SubscriptionInput;
use App\Subscriptions\Domain\Entity\Subscription;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Da de alta una suscripción del catálogo.
 *
 * La organización sale del contexto de tenant y no de la petición: si viniera
 * del formulario, un `POST` manipulado podría crear la suscripción en otra
 * organización.
 */
final readonly class CreateSubscription
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptions,
        private TenantContext $tenantContext,
    ) {
    }

    public function __invoke(SubscriptionInput $input, ?Uuid $actorId = null): Subscription
    {
        $subscription = new Subscription(
            organizationId: $this->tenantContext->requireOrganizationId(),
            name: $input->name,
            currency: $input->currency,
            billingPeriod: $input->billingPeriod,
            billingIntervalCount: $input->billingIntervalCount,
            startedAt: $input->startedAt,
            createdByUserId: $actorId,
        );

        $subscription->changePlan($input->planId, $input->name);
        $subscription->linkService($input->serviceId);
        $subscription->setExternalReference($input->externalReference);

        $this->subscriptions->save($subscription);

        return $subscription;
    }
}
