<?php

declare(strict_types=1);

namespace App\Subscriptions\Application;

use App\Subscriptions\Application\Dto\SubscriptionInput;
use App\Subscriptions\Domain\Entity\Subscription;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;

/**
 * Corrige los datos de una suscripción existente.
 *
 * No toca el estado ni el periodo: para eso están `ChangeSubscriptionStatus`,
 * `CancelSubscription` y `RenewSubscription`, que tienen reglas propias. Aquí
 * solo se corrigen datos que se escribieron mal.
 */
final readonly class UpdateSubscription
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptions,
    ) {
    }

    public function __invoke(Subscription $subscription, SubscriptionInput $input): Subscription
    {
        $subscription->rename($input->name);
        $subscription->changePlan($input->planId, $input->name);
        $subscription->linkService($input->serviceId);
        $subscription->setExternalReference($input->externalReference);

        if ($subscription->getBillingPeriod() !== $input->billingPeriod
            || $subscription->getBillingIntervalCount() !== $input->billingIntervalCount) {
            $subscription->changeBillingPeriod($input->billingPeriod, $input->billingIntervalCount);
        }

        $this->subscriptions->save($subscription);

        return $subscription;
    }
}
