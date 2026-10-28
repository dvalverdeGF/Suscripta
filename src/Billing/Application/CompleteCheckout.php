<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\Port\PaymentGatewayInterface;
use App\Billing\Domain\ValueObject\GatewayCheckoutSession;
use App\Shared\Application\TenantContext;
use Psr\Log\LoggerInterface;

/**
 * Cierra el checkout al volver de la pasarela (Fase 24).
 *
 * ## Por qué se consulta la sesión y no se espera al webhook
 *
 * El webhook es la fuente de verdad, pero llega cuando llega: puede tardar
 * segundos, puede reintentar, y en un despliegue puede quedarse en cola. El
 * cliente que acaba de pagar está delante de la pantalla y tiene que ver su
 * plan activo **ya**. Así que al volver se pregunta por la sesión, y si está
 * `complete` se aplica en el acto.
 *
 * Aplicar dos veces no rompe nada: `ApplyGatewaySubscription` busca por la
 * referencia externa y sincroniza, y el webhook que llegue después hará lo
 * mismo con los mismos datos.
 *
 * ## La sesión tiene que ser de esta organización
 *
 * Si la sesión no lleva el identificador de la organización activa, no se
 * aplica nada: alguien ha pegado un `session_id` de otra cuenta en la URL.
 * Es una comprobación de propiedad, no de existencia.
 */
final readonly class CompleteCheckout
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private ApplyGatewaySubscription $applySubscription,
        private TenantContext $tenantContext,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool si la suscripción ha quedado aplicada
     */
    public function __invoke(string $sessionId): bool
    {
        if (!$this->gateway->isConfigured()) {
            return false;
        }

        $session = $this->gateway->findCheckoutSession($sessionId);

        if (null === $session || !$session->isComplete()) {
            return false;
        }

        $organizationId = $this->tenantContext->getOrganizationId();

        if (null === $organizationId || !$this->belongsTo($session, (string) $organizationId)) {
            $this->logger->warning('Se ignora una vuelta de checkout de otra organización.', [
                'session' => $session->externalId,
            ]);

            return false;
        }

        if (null === $session->subscriptionExternalId) {
            return false;
        }

        $remote = $this->gateway->findSubscription($session->subscriptionExternalId);

        if (null === $remote) {
            return false;
        }

        return null !== $this->applySubscription->__invoke($remote);
    }

    private function belongsTo(GatewayCheckoutSession $session, string $organizationId): bool
    {
        return null !== $session->organizationId && $session->organizationId->toRfc4122() === $organizationId;
    }
}
