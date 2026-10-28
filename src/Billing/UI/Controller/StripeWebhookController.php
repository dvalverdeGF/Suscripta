<?php

declare(strict_types=1);

namespace App\Billing\UI\Controller;

use App\Billing\Application\HandlePaymentEvent;
use App\Billing\Domain\Exception\GatewayNotConfiguredException;
use App\Billing\Domain\Exception\InvalidSignatureException;
use App\Billing\Domain\Port\PaymentGatewayInterface;
use Psr\Log\LoggerInterface;

use function sprintf;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Recibe los avisos de la pasarela (Fase 24).
 *
 * ## Es público, y por eso la firma lo es todo
 *
 * No hay sesión ni usuario detrás de un webhook. Lo único que distingue un
 * aviso legítimo de alguien publicando `{"type":"invoice.paid"}` es la firma
 * HMAC del cuerpo, que se verifica **antes** de mirar el contenido. Sin firma
 * válida se responde 400 y no se toca la base de datos.
 *
 * ## Los códigos de respuesta son parte del protocolo
 *
 * | Situación | Código | Por qué |
 * |---|---|---|
 * | Aplicado | 200 | Nada más que hacer |
 * | Repetido | 200 | Ya estaba: reintentar no cambiaría nada |
 * | Tipo desconocido | 200 | Devolver error lo haría reintentar durante días |
 * | Firma inválida | 400 | No es un aviso de la pasarela |
 * | Fallo inesperado | 500 | Que reintente: puede ser pasajero |
 *
 * ## El cuerpo se lee en crudo
 *
 * La firma se calcula sobre los bytes exactos que se enviaron. Cualquier
 * `json_decode` + `json_encode` de por medio cambia espacios y orden de claves,
 * y la firma deja de cuadrar. Por eso se usa `getContent()` y no
 * `$request->request`.
 */
final class StripeWebhookController
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly HandlePaymentEvent $handleEvent,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/webhooks/stripe', name: 'app_billing_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = $request->headers->get('Stripe-Signature');

        try {
            $event = $this->gateway->parseEvent($payload, (string) $signature);
        } catch (InvalidSignatureException $exception) {
            $this->logger->warning('Webhook de Stripe con firma inválida.', ['error' => $exception->getMessage()]);

            return new Response('Firma inválida.', Response::HTTP_BAD_REQUEST);
        } catch (GatewayNotConfiguredException $exception) {
            $this->logger->error('Webhook de Stripe sin secreto de firma configurado.');

            return new Response('La pasarela no está configurada.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $outcome = ($this->handleEvent)($event);
        } catch (Throwable $exception) {
            // Se responde 500 para que la pasarela lo reintente: el evento ya
            // se ha soltado y el reintento se procesará de verdad.
            $this->logger->error('Fallo al aplicar un evento de Stripe.', [
                'event' => $event->id,
                'type' => $event->type,
                'error' => $exception->getMessage(),
            ]);

            return new Response('No se ha podido aplicar el evento.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response(sprintf('ok:%s', $outcome->value), Response::HTTP_OK);
    }
}
