<?php

declare(strict_types=1);

namespace App\Subscriptions\Application;

use App\Subscriptions\Domain\Entity\Subscription;
use App\Subscriptions\Domain\Enum\SubscriptionStatus;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use InvalidArgumentException;

use function sprintf;

/**
 * Mueve una suscripción entre estados vivos (activar, impagar, pausar,
 * reanudar).
 *
 * Cancelar **no** está aquí: es una operación con fecha de fin y tiene su
 * propio caso de uso. Y las reglas de qué transición es válida viven en la
 * entidad, no en este servicio: así valen también para el cobrador de la fase
 * 24, que llamará a los mismos métodos.
 */
final readonly class ChangeSubscriptionStatus
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptions,
    ) {
    }

    public function __invoke(Subscription $subscription, SubscriptionStatus $status): Subscription
    {
        switch ($status) {
            case SubscriptionStatus::ACTIVE:
                if (SubscriptionStatus::PAUSED === $subscription->getStatus()) {
                    $subscription->resume();
                } else {
                    $subscription->activate();
                }
                break;
            case SubscriptionStatus::PAST_DUE:
                $subscription->markPastDue();
                break;
            case SubscriptionStatus::PAUSED:
                $subscription->pause();
                break;
            case SubscriptionStatus::TRIALING:
            case SubscriptionStatus::CANCELED:
                throw new InvalidArgumentException(sprintf('El estado «%s» no se cambia a mano.', $status->label()));
        }

        $this->subscriptions->save($subscription);

        return $subscription;
    }
}
