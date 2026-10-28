<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\Exception\GatewayNotConfiguredException;
use App\Billing\Domain\Port\PaymentGatewayInterface;
use App\Billing\Domain\ValueObject\CheckoutRequest;
use App\Billing\Domain\ValueObject\CheckoutSession;
use App\Shared\Application\TenantContext;
use App\Subscriptions\Domain\Entity\PlanPrice;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Abre una sesión de pago para un precio del catálogo (Fase 24).
 *
 * ## Qué comprueba antes de mandar al cliente a pagar
 *
 * 1. Que la pasarela esté configurada. Si no lo está, el error se dice aquí y
 *    no después de que el cliente haya rellenado sus datos.
 * 2. Que el precio sea realmente comprable: vigente, de un plan activo y con
 *    importe mayor que cero. Un precio histórico (`validTo` no nulo) no se
 *    ofrece, aunque alguien tenga la URL del año pasado en un correo.
 * 3. Que la organización no tenga ya una suscripción viva al mismo plan. Sin
 *    esto, dos clics dejan dos cobros recurrentes y hay que devolver uno a mano.
 *
 * ## Lo que no hace
 *
 * No crea la suscripción. La suscripción la crea el pago, no la intención de
 * pagar: si se creara al abrir el checkout, cada cliente que abandona el pago
 * dejaría una suscripción fantasma ocupando plaza del plan.
 */
final readonly class StartCheckout
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private SubscriptionRepositoryInterface $subscriptions,
        private TenantContext $tenantContext,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @throws CheckoutUnavailableException  cuando el precio no se puede vender
     * @throws GatewayNotConfiguredException cuando faltan credenciales
     */
    public function __invoke(PlanPrice $price, ?string $customerEmail = null): CheckoutSession
    {
        if (!$this->gateway->isConfigured()) {
            throw GatewayNotConfiguredException::missingSecretKey();
        }

        $plan = $price->getPlan();

        if (!$price->isCurrent()) {
            throw CheckoutUnavailableException::priceNotCurrent($price);
        }

        if (!$plan->isActive()) {
            throw CheckoutUnavailableException::planRetired($plan->getName());
        }

        if ($price->getAmount()->amountMinor <= 0) {
            throw CheckoutUnavailableException::freePlan($plan->getName());
        }

        $organizationId = $this->tenantContext->requireOrganizationId();

        if ($this->alreadySubscribed($price)) {
            throw CheckoutUnavailableException::alreadySubscribed($plan->getName());
        }

        return $this->gateway->createCheckoutSession(new CheckoutRequest(
            organizationId: $organizationId,
            planId: $plan->getId(),
            planName: $plan->getName(),
            amount: $price->getAmount(),
            billingPeriod: $price->getBillingPeriod(),
            billingIntervalCount: 1,
            externalPriceId: $price->getExternalPriceId(),
            customerEmail: $customerEmail,
            successUrl: $this->urls->generate('app_billing_return', [], UrlGeneratorInterface::ABSOLUTE_URL).'?session_id={CHECKOUT_SESSION_ID}',
            cancelUrl: $this->urls->generate('app_site_pricing', [], UrlGeneratorInterface::ABSOLUTE_URL).'?pago=cancelado',
        ));
    }

    /**
     * Si ya hay una suscripción viva a este plan.
     *
     * Se compara el plan y no el precio: cambiar de mensual a anual es un
     * cambio de precio del mismo contrato, y bloquearlo obligaría a cancelar y
     * volver a contratar perdiendo el periodo pagado.
     */
    private function alreadySubscribed(PlanPrice $price): bool
    {
        foreach ($this->subscriptions->findForOrganization() as $subscription) {
            if ($subscription->isLive() && $subscription->getPlanId()?->equals($price->getPlan()->getId())) {
                return true;
            }
        }

        return false;
    }
}
