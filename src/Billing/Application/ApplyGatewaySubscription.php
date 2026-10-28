<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\ValueObject\GatewaySubscription;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Subscriptions\Domain\Entity\Subscription;
use App\Subscriptions\Domain\Enum\SubscriptionStatus;
use App\Subscriptions\Domain\Repository\PlanRepositoryInterface;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Refleja en Suscripta lo que la pasarela dice de una suscripción (Fase 24).
 *
 * ## La organización y el plan salen de los metadatos
 *
 * La pasarela no sabe qué es una organización: el identificador se copia a los
 * metadatos al abrir el checkout y vuelve con cada evento. Si un evento llega
 * sin organización —un evento creado a mano en el panel de Stripe, por
 * ejemplo— **no se aplica**: es preferible una suscripción sin actualizar que
 * una actualizada en la organización equivocada.
 *
 * ## Crear y actualizar son el mismo camino
 *
 * `checkout.session.completed` y `customer.subscription.updated` describen la
 * misma cosa en momentos distintos. Se busca por la referencia externa y, si no
 * existe, se crea. Eso hace que un `updated` que llega antes que el `completed`
 * —pasa cuando el pago tarda— acabe igualmente con la suscripción correcta, en
 * lugar de perderse por no encontrarla.
 */
final readonly class ApplyGatewaySubscription
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptions,
        private PlanRepositoryInterface $plans,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return Subscription|null la suscripción resultante, o `null` si el
     *                           evento no se ha podido aplicar
     */
    public function __invoke(GatewaySubscription $remote): ?Subscription
    {
        $status = $remote->status->toSubscriptionStatus();

        if (null === $status) {
            $this->logger->info('Se ignora un estado de suscripción que no se traduce.', [
                'external_id' => $remote->externalId,
                'status' => $remote->status->value,
            ]);

            return null;
        }

        $existing = $this->subscriptions->findByExternalReference($remote->externalId);

        if (null !== $existing) {
            $existing->syncWithGateway(
                status: $status,
                currentPeriodStart: $remote->currentPeriodStart,
                currentPeriodEnd: $remote->currentPeriodEnd,
                canceledAt: $remote->canceledAt,
                endsAt: $remote->cancelAtPeriodEnd ? $remote->currentPeriodEnd : null,
            );

            $this->subscriptions->save($existing);

            return $existing;
        }

        if (null === $remote->organizationId) {
            $this->logger->warning('Se ignora una suscripción de la pasarela sin organización en los metadatos.', [
                'external_id' => $remote->externalId,
            ]);

            return null;
        }

        $subscription = $this->create($remote, $remote->organizationId, $status);

        $this->subscriptions->save($subscription);

        return $subscription;
    }

    private function create(GatewaySubscription $remote, Uuid $organizationId, SubscriptionStatus $status): Subscription
    {
        $plan = null !== $remote->planId ? $this->plans->find($remote->planId) : null;

        $name = $remote->planName
            ?? $plan?->getName()
            ?? 'Suscripción';

        $currency = $remote->currency ?? Currency::EUR;
        $billingPeriod = $remote->billingPeriod ?? BillingPeriod::MONTHLY;

        if (!$billingPeriod->isRecurring()) {
            $this->logger->warning('Se ignora una suscripción con una periodicidad que no se cobra.', [
                'external_id' => $remote->externalId,
                'period' => $billingPeriod->value,
            ]);

            $billingPeriod = BillingPeriod::MONTHLY;
        }

        // El periodo de la pasarela manda: se crea con él y no con «hoy», o una
        // suscripción anual parecería recién empezada cada vez que se recrea.
        $subscription = new Subscription(
            organizationId: $organizationId,
            name: $name,
            currency: $currency,
            billingPeriod: $billingPeriod,
            billingIntervalCount: 1,
            startedAt: $remote->currentPeriodStart,
            status: $status,
            createdByUserId: null,
        );

        $subscription->changePlan($plan?->getId(), $name);
        $subscription->syncWithGateway(
            status: $status,
            currentPeriodStart: $remote->currentPeriodStart,
            currentPeriodEnd: $remote->currentPeriodEnd,
            canceledAt: $remote->canceledAt,
            endsAt: $remote->cancelAtPeriodEnd ? $remote->currentPeriodEnd : null,
            externalReference: $remote->externalId,
        );

        $this->logger->info('Alta de suscripción desde la pasarela.', [
            'external_id' => $remote->externalId,
            'organization_id' => (string) $remote->organizationId,
            'plan' => $name,
        ]);

        return $subscription;
    }
}
