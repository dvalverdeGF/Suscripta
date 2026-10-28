<?php

declare(strict_types=1);

namespace App\Subscriptions\Application;

use App\Subscriptions\Domain\Entity\Subscription;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use DateTimeImmutable;

/**
 * Marca una suscripción como renovada: adelanta el periodo.
 *
 * Existe a mano porque hoy no hay cobrador que lo haga solo (fase 24). Con
 * Stripe delante, este caso de uso será lo que ejecute el webhook de
 * `invoice.paid`, así que la regla de negocio ya está escrita y probada.
 */
final readonly class RenewSubscription
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptions,
    ) {
    }

    public function __invoke(Subscription $subscription, ?DateTimeImmutable $today = null): Subscription
    {
        $subscription->renew($today);
        $this->subscriptions->save($subscription);

        return $subscription;
    }
}
