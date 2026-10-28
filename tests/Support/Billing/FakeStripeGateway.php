<?php

declare(strict_types=1);

namespace App\Tests\Support\Billing;

use App\Billing\Domain\Exception\GatewayNotConfiguredException;
use App\Billing\Domain\Exception\InvalidSignatureException;
use App\Billing\Domain\Port\PaymentGatewayInterface;
use App\Billing\Domain\ValueObject\CheckoutRequest;
use App\Billing\Domain\ValueObject\CheckoutSession;
use App\Billing\Domain\ValueObject\GatewayCheckoutSession;
use App\Billing\Domain\ValueObject\GatewayEvent;
use App\Billing\Domain\ValueObject\GatewayInvoice;
use App\Billing\Domain\ValueObject\GatewaySubscription;

use function array_key_exists;
use function count;

use DateTimeImmutable;

use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

use function sprintf;

/**
 * Pasarela de mentira para los tests (Fase 24).
 *
 * ## Por qué existe
 *
 * El cobro se prueba con red cero: no hay cuenta de Stripe en la suite ni debe
 * haberla. Este doble implementa el mismo puerto que la pasarela real, así que
 * los controladores, el webhook y la conciliación se ejecutan **tal y como se
 * ejecutan en producción**, sin `if (test)` por ninguna parte.
 *
 * ## Es controlable, no un mock de expectativas
 *
 * Los tests preparan lo que la pasarela «sabe» (`withSubscription`,
 * `withCheckoutSession`) y luego miran lo que la aplicación ha hecho. Un mock
 * con expectativas obligaría a escribir el test en el orden en que el código
 * llama, y el test dejaría de contar la historia del cobro.
 */
final class FakeStripeGateway implements PaymentGatewayInterface
{
    public bool $configured = true;

    /**
     * Lo que la pasarela devuelve al abrir un checkout. Se puede cambiar para
     * probar una respuesta sin URL.
     */
    public ?CheckoutSession $nextCheckoutSession = null;

    /**
     * @var list<CheckoutRequest>
     */
    public array $checkoutRequests = [];

    /**
     * @var array<string, GatewaySubscription>
     */
    private array $subscriptions = [];

    /**
     * @var array<string, GatewayCheckoutSession>
     */
    private array $checkoutSessions = [];

    /**
     * Si el evento que se parsee debe considerarse mal firmado.
     */
    public bool $rejectSignatures = false;

    /**
     * Si hay secreto de firma configurado. Apagarlo reproduce el caso en que
     * llega un webhook antes de que nadie haya pegado el `whsec_…`.
     */
    public bool $webhookSecretConfigured = true;

    /**
     * @var array<string, GatewayInvoice>
     */
    private array $invoices = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function withSubscription(GatewaySubscription $subscription): self
    {
        $this->subscriptions[$subscription->externalId] = $subscription;

        return $this;
    }

    public function forgetSubscription(string $externalId): self
    {
        unset($this->subscriptions[$externalId]);

        return $this;
    }

    public function withInvoice(GatewayInvoice $invoice): self
    {
        $this->invoices[$invoice->externalId] = $invoice;

        return $this;
    }

    public function withCheckoutSession(GatewayCheckoutSession $session): self
    {
        $this->checkoutSessions[$session->externalId] = $session;

        return $this;
    }

    public function createCheckoutSession(CheckoutRequest $request): CheckoutSession
    {
        $this->checkoutRequests[] = $request;

        return $this->nextCheckoutSession ?? new CheckoutSession(
            externalId: sprintf('cs_test_%d', count($this->checkoutRequests)),
            url: 'https://checkout.stripe.test/session',
            expiresAt: new DateTimeImmutable('+1 day'),
        );
    }

    public function findCheckoutSession(string $externalId): ?GatewayCheckoutSession
    {
        return $this->checkoutSessions[$externalId] ?? null;
    }

    public function findSubscription(string $externalId): ?GatewaySubscription
    {
        return $this->subscriptions[$externalId] ?? null;
    }

    /**
     * En los tests, el evento se construye a mano y la firma no se verifica:
     * lo que se prueba es lo que pasa **después** de verificarla. La
     * verificación real tiene su propio test (`StripeSignatureVerifierTest`).
     */
    public function parseEvent(string $payload, string $signatureHeader): GatewayEvent
    {
        if ($this->rejectSignatures) {
            throw InvalidSignatureException::mismatch();
        }

        if (!$this->webhookSecretConfigured) {
            throw GatewayNotConfiguredException::missingWebhookSecret();
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        $id = $data['id'] ?? 'evt_test';
        $type = $data['type'] ?? '';

        return new GatewayEvent(
            id: (string) $id,
            type: (string) $type,
            createdAt: new DateTimeImmutable(),
            subscription: $this->eventSubscription($data),
            checkoutSession: $this->eventCheckoutSession($data),
            invoice: $this->eventInvoice($data),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function eventSubscription(array $data): ?GatewaySubscription
    {
        $id = $data['subscription'] ?? null;

        return is_string($id) && array_key_exists($id, $this->subscriptions) ? $this->subscriptions[$id] : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function eventInvoice(array $data): ?GatewayInvoice
    {
        $id = $data['invoice'] ?? null;

        return is_string($id) && array_key_exists($id, $this->invoices) ? $this->invoices[$id] : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function eventCheckoutSession(array $data): ?GatewayCheckoutSession
    {
        $id = $data['session'] ?? null;

        return is_string($id) && array_key_exists($id, $this->checkoutSessions) ? $this->checkoutSessions[$id] : null;
    }
}
