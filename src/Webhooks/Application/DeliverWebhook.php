<?php

declare(strict_types=1);

namespace App\Webhooks\Application;

use App\Mailbox\Application\CredentialCipherInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Webhooks\Domain\Entity\WebhookDelivery;
use App\Webhooks\Domain\Exception\UnsafeWebhookUrlException;
use App\Webhooks\Domain\Repository\WebhookDeliveryRepositoryInterface;
use App\Webhooks\Domain\Repository\WebhookRepositoryInterface;
use App\Webhooks\Domain\Service\RetryPolicy;
use App\Webhooks\Domain\Service\WebhookSigner;

use function is_string;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use Psr\Log\LoggerInterface;

use function sprintf;

use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Hace un intento de entrega y decide qué pasa después (Fase 20).
 *
 * ## Qué cuenta como éxito
 *
 * Cualquier `2xx`. Un `3xx` no: el receptor tiene que responder en la URL que
 * dio, no redirigir. Un `4xx` distinto de `429` se reintenta igualmente porque
 * el caso típico es un receptor mal configurado que el operador arregla en
 * minutos; abandonar a la primera obligaría a reenviar a mano.
 *
 * ## Qué no hace
 *
 * No lanza excepciones. Un fallo de red es un resultado normal de este caso de
 * uso y se guarda en la fila, no en la cola: si el handler lanzara, Messenger
 * reintentaría el mensaje con su propia política, y tendríamos dos sistemas de
 * reintento discutiéndose. La política vive aquí, en `RetryPolicy`, y es la
 * única que decide cuándo se vuelve a intentar.
 *
 * ## Aislamiento
 *
 * El worker no tiene organización activa. Se entra en la del suscriptor con
 * `runAs()` antes de tocar nada, porque el filtro de Doctrine esconde las
 * filas de otras organizaciones y sin él la entrega no se encontraría.
 *
 * ## La URL pasa por la guarda anti-SSRF en **cada** intento
 *
 * No solo al dar de alta al suscriptor. El DNS cambia, y un host que hoy
 * resuelve a una dirección pública puede resolver mañana a una privada. Si la
 * guarda rechaza el destino, la entrega se abandona **en el acto**, sin
 * reintentos: reintentar ocho veces durante siete días una URL que es un error
 * de configuración no la va a arreglar, y solo llena el historial de ruido.
 */
final readonly class DeliverWebhook
{
    public function __construct(
        private WebhookDeliveryRepositoryInterface $deliveries,
        private WebhookRepositoryInterface $webhooks,
        private CredentialCipherInterface $cipher,
        private WebhookSigner $signer,
        private RetryPolicy $retryPolicy,
        private WebhookUrlGuard $urlGuard,
        private HttpClientInterface $httpClient,
        private TenantContext $tenantContext,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Uuid $deliveryId): void
    {
        $probe = $this->deliveries->find($deliveryId);

        if (null === $probe) {
            return;
        }

        $this->tenantContext->runAs($probe->getOrganizationId(), function () use ($deliveryId): void {
            $this->attempt($deliveryId);
        });
    }

    private function attempt(Uuid $deliveryId): void
    {
        $delivery = $this->deliveries->find($deliveryId);

        if (null === $delivery) {
            return;
        }

        // Un mensaje duplicado en la cola (o un reintento que llega después de
        // que otro proceso ya lo consiguiera) no debe reenviar nada.
        if (!$delivery->getStatus()->isPending()) {
            return;
        }

        $webhook = $this->webhooks->find($delivery->getWebhookId());

        if (null === $webhook) {
            $delivery->abandon('El suscriptor se ha borrado.', $this->clock->now());
            $this->deliveries->save($delivery);

            return;
        }

        if (!$webhook->isActive()) {
            $delivery->abandon('El suscriptor está pausado.', $this->clock->now());
            $this->deliveries->save($delivery);

            return;
        }

        try {
            $target = $this->urlGuard->pin($webhook->getUrl());
        } catch (UnsafeWebhookUrlException $exception) {
            $delivery->abandon($exception->getMessage(), $this->clock->now());
            $this->deliveries->save($delivery);
            $this->logger->error('Entrega de webhook abandonada: la URL apunta a una red interna.', [
                'deliveryId' => (string) $delivery->getId(),
                'url' => $webhook->getUrl(),
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $body = $this->encode($delivery);
        $secret = $this->cipher->decrypt($webhook->getSecretEncrypted());
        $timestamp = $this->clock->now()->getTimestamp();

        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Suscripta-Webhooks/1.0',
                WebhookSigner::HEADER => $this->signer->header($secret, $timestamp, $body),
                'Idempotency-Key' => $delivery->getIdempotencyKey(),
                'X-Suscripta-Event' => $delivery->getEvent()->value,
            ],
            'body' => $body,
            'timeout' => 10,
            'max_duration' => 20,
            // Un `302` desde un host público hacia `http://169.254.169.254/`
            // sería el mismo ataque con un salto intermedio. Un webhook
            // responde en la URL que dio o no responde.
            'max_redirects' => 0,
        ];

        if (null !== $target) {
            // Conectar a la dirección ya validada en vez de volver a preguntar
            // al DNS: cierra la ventana del DNS rebinding.
            $options['resolve'] = [$target->host => $target->ip];
        }

        try {
            $response = $this->httpClient->request('POST', $webhook->getUrl(), $options);

            $status = $response->getStatusCode();
        } catch (HttpClientExceptionInterface $exception) {
            $this->recordFailure($delivery, null, $exception->getMessage());

            return;
        }

        if ($status >= 200 && $status < 300) {
            $delivery->markDelivered($status, $this->clock->now());
            $this->deliveries->save($delivery);
            $webhook->markDelivered($this->clock->now());
            $this->webhooks->save($webhook);

            return;
        }

        $this->recordFailure($delivery, $status, sprintf('El receptor respondió %d.', $status));
    }

    private function recordFailure(WebhookDelivery $delivery, ?int $status, string $error): void
    {
        $now = $this->clock->now();
        $nextAttemptAt = $this->retryPolicy->nextAttemptAt($delivery->getAttempts() + 1, $now);

        if (null === $nextAttemptAt) {
            $delivery->abandon($error, $now);
            $this->deliveries->save($delivery);
            $this->logger->warning('Entrega de webhook abandonada tras agotar reintentos.', [
                'deliveryId' => (string) $delivery->getId(),
                'event' => $delivery->getEvent()->value,
                'attempts' => $delivery->getAttempts(),
                'error' => $error,
            ]);

            return;
        }

        $delivery->markFailed($status, $error, $nextAttemptAt, $now);
        $this->deliveries->save($delivery);
    }

    /**
     * El cuerpo se serializa **una vez** y se guarda.
     *
     * Reintentar reutiliza la misma cadena, así que la firma del reintento
     * cubre exactamente lo que el receptor recibió la primera vez. Si se
     * regenerara el JSON, el orden de las claves podría cambiar y el receptor
     * que compare cuerpos vería dos eventos distintos.
     */
    private function encode(WebhookDelivery $delivery): string
    {
        $encoded = json_encode($delivery->getPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '{}';
    }
}
