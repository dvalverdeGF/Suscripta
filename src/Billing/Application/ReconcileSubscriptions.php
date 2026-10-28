<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\Port\PaymentGatewayInterface;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use Psr\Log\LoggerInterface;

use function sprintf;

/**
 * Pone al día las suscripciones de Suscripta contra la pasarela (Fase 24).
 *
 * ## Por qué hace falta aunque haya webhooks
 *
 * Un webhook se pierde: el servidor estaba reiniciando, el puerto estaba
 * cerrado, la firma cambió y nadie la actualizó. Stripe reintenta durante tres
 * días y después se rinde. Cuando se rinde, la suscripción local se queda con
 * el estado de hace una semana y el cliente paga sin que su plan lo refleje.
 *
 * Esta pasada compara y corrige. No adivina nada: pregunta por cada
 * suscripción y aplica lo que la pasarela conteste, con el mismo código que el
 * webhook, así que los dos caminos no pueden dar resultados distintos.
 *
 * ## Lo que no hace
 *
 * **No cancela lo que no encuentra.** Una suscripción que la pasarela ya no
 * conoce se deja como está y se apunta en el log: puede ser un borrado hecho a
 * mano en Stripe o un fallo puntual de la consulta, y cancelar por eso dejaría
 * a un cliente de baja sin motivo.
 */
final readonly class ReconcileSubscriptions
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private SubscriptionRepositoryInterface $subscriptions,
        private ApplyGatewaySubscription $applySubscription,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{checked: int, updated: int, missing: int}
     */
    public function __invoke(): array
    {
        $checked = 0;
        $updated = 0;
        $missing = 0;

        if (!$this->gateway->isConfigured()) {
            $this->logger->warning('No se concilia la facturación: la pasarela no está configurada.');

            return ['checked' => 0, 'updated' => 0, 'missing' => 0];
        }

        foreach ($this->subscriptions->findBillable() as $subscription) {
            $reference = $subscription->getExternalReference();

            if (null === $reference) {
                continue;
            }

            ++$checked;

            $remote = $this->gateway->findSubscription($reference);

            if (null === $remote) {
                ++$missing;

                $this->logger->warning('La pasarela no conoce una suscripción local.', [
                    'external_reference' => $reference,
                ]);

                continue;
            }

            if (null !== $this->applySubscription->__invoke($remote)) {
                ++$updated;
            }
        }

        $this->logger->info('Conciliación de facturación terminada.', [
            'checked' => $checked,
            'updated' => $updated,
            'missing' => $missing,
        ]);

        return ['checked' => $checked, 'updated' => $updated, 'missing' => $missing];
    }

    /**
     * Resumen en una frase, para el comando y para el panel de integraciones.
     *
     * @param array{checked: int, updated: int, missing: int} $result
     */
    public static function describe(array $result): string
    {
        return sprintf(
            '%d suscripciones revisadas, %d actualizadas, %d que la pasarela no conoce.',
            $result['checked'],
            $result['updated'],
            $result['missing'],
        );
    }
}
