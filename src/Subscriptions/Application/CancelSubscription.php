<?php

declare(strict_types=1);

namespace App\Subscriptions\Application;

use App\Subscriptions\Domain\Entity\Subscription;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use DateTimeImmutable;

/**
 * Cancela una suscripción al final del periodo pagado.
 *
 * Es idempotente: cancelar dos veces deja la misma fecha de fin. El cobrador
 * (fase 24) recibe el evento de Stripe varias veces y no puede fallar por eso.
 */
final readonly class CancelSubscription
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptions,
    ) {
    }

    public function __invoke(Subscription $subscription, ?DateTimeImmutable $today = null): Subscription
    {
        $subscription->cancel($today);
        $this->subscriptions->save($subscription);

        return $subscription;
    }
}
