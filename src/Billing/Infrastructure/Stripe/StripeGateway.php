<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Stripe;

use App\Billing\Domain\Enum\GatewaySubscriptionStatus;
use App\Billing\Domain\Enum\InvoiceStatus;
use App\Billing\Domain\Exception\GatewayNotConfiguredException;
use App\Billing\Domain\Exception\GatewayRequestException;
use App\Billing\Domain\Exception\InvalidSignatureException;
use App\Billing\Domain\Port\PaymentGatewayInterface;
use App\Billing\Domain\ValueObject\CheckoutRequest;
use App\Billing\Domain\ValueObject\CheckoutSession;
use App\Billing\Domain\ValueObject\GatewayCheckoutSession;
use App\Billing\Domain\ValueObject\GatewayEvent;
use App\Billing\Domain\ValueObject\GatewayInvoice;
use App\Billing\Domain\ValueObject\GatewaySubscription;
use App\Integrations\Application\IntegrationConfigReader;
use App\Integrations\Application\IntegrationRegistry;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;

use function array_filter;
use function array_merge;

use DateTimeImmutable;

use function http_build_query;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;

use Psr\Log\LoggerInterface;

use function sprintf;
use function str_starts_with;
use function strtolower;
use function substr;

use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function trim;

/**
 * Implementación de la pasarela sobre la API REST de Stripe (Fase 24).
 *
 * ## Sin SDK
 *
 * Se usan cinco operaciones de la API, todas estables desde hace años. El SDK
 * oficial añade una dependencia grande, su propio cliente HTTP y su propio
 * cargador para ahorrar cuatro llamadas; aquí se hace con
 * `symfony/http-client`, que ya está en el proyecto y se puede sustituir por un
 * doble en los tests. La firma del webhook se verifica a mano en
 * `StripeSignatureVerifier`, con el mismo criterio que el resto de webhooks de
 * la aplicación.
 *
 * ## Las credenciales viven en el panel de integraciones
 *
 * No en `.env`: son datos de la organización, no del despliegue, y el operador
 * tiene que poder rotarlas sin tocar el servidor. Se leen con
 * `IntegrationConfigReader`, que es el único sitio que las descifra.
 *
 * ## Todo lo que entra se comprueba antes de creérselo
 *
 * Stripe devuelve JSON con campos opcionales y versiones que cambian. Cada
 * lectura pasa por un ayudante que devuelve `null` en vez de fallar, y lo que
 * falta se trata como ausente: un `current_period_end` que no llega no puede
 * tumbar el webhook y dejar el cobro sin aplicar.
 */
final class StripeGateway implements PaymentGatewayInterface
{
    private const string API_BASE = 'https://api.stripe.com';

    /**
     * Versión de la API fijada a propósito.
     *
     * Sin `Stripe-Version`, la cuenta usa la de su configuración y una
     * actualización hecha en el panel de Stripe puede cambiar la forma de la
     * respuesta sin que nadie toque este repositorio. Fijarla hace que la
     * actualización sea una decisión y no una sorpresa.
     */
    private const string API_VERSION = '2024-06-20';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly IntegrationConfigReader $config,
        private readonly StripeSignatureVerifier $verifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->secretKey(quiet: true);
    }

    public function createCheckoutSession(CheckoutRequest $request): CheckoutSession
    {
        $body = [
            'mode' => 'subscription',
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'client_reference_id' => $request->organizationId->toRfc4122(),
            'line_items[0][quantity]' => '1',
        ];

        if (null !== $request->customerEmail && '' !== $request->customerEmail) {
            $body['customer_email'] = $request->customerEmail;
        }

        $metadata = [
            'organization_id' => $request->organizationId->toRfc4122(),
            'plan_id' => $request->planId->toRfc4122(),
            'plan_name' => $request->planName,
            'billing_period' => $request->billingPeriod->value,
        ];

        $body = array_merge($body, $this->lineItem($request), $this->flatten('metadata', $metadata));

        // La suscripción nace con los mismos metadatos que la sesión: es lo que
        // permite que un `customer.subscription.updated` que llegue suelto,
        // sin sesión detrás, sepa a qué organización pertenece.
        $body = array_merge($body, $this->flatten('subscription_data[metadata]', $metadata));

        $response = $this->post('checkout.sessions', $body, 'checkout-'.$request->organizationId->toRfc4122().'-'.$request->planId->toRfc4122());

        $id = $this->string($response, 'id');
        $url = $this->string($response, 'url');

        if (null === $id || null === $url) {
            throw GatewayRequestException::failed('checkout.sessions', 200, 'la respuesta no trae identificador o URL de pago');
        }

        return new CheckoutSession(
            externalId: $id,
            url: $url,
            expiresAt: $this->date($response, 'expires_at') ?? new DateTimeImmutable('+24 hours'),
        );
    }

    public function findCheckoutSession(string $externalId): ?GatewayCheckoutSession
    {
        $response = $this->get('checkout/sessions/'.$externalId, ['expand[0]' => 'subscription']);

        if (null === $response) {
            return null;
        }

        $metadata = $this->map($response, 'metadata');

        return new GatewayCheckoutSession(
            externalId: $this->string($response, 'id') ?? $externalId,
            status: $this->string($response, 'status') ?? 'open',
            subscriptionExternalId: $this->reference($response, 'subscription'),
            organizationId: $this->uuid($metadata, 'organization_id'),
            planId: $this->uuid($metadata, 'plan_id'),
            planName: $this->string($metadata, 'plan_name'),
            billingPeriod: $this->billingPeriod($metadata),
        );
    }

    public function findSubscription(string $externalId): ?GatewaySubscription
    {
        $response = $this->get('subscriptions/'.$externalId);

        if (null === $response) {
            return null;
        }

        return $this->mapSubscription($response, $externalId);
    }

    public function parseEvent(string $payload, string $signatureHeader): GatewayEvent
    {
        $secret = $this->webhookSecret();

        $this->verifier->verify($payload, $signatureHeader, $secret);

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            throw InvalidSignatureException::malformed('el cuerpo no es un objeto JSON');
        }

        $type = $this->string($decoded, 'type') ?? '';
        $object = $this->map($decoded, 'data');
        $object = $this->map($object, 'object');

        return new GatewayEvent(
            id: $this->string($decoded, 'id') ?? '',
            type: $type,
            createdAt: $this->date($decoded, 'created') ?? new DateTimeImmutable(),
            subscription: $this->subscriptionFromEvent($type, $object),
            checkoutSession: $this->checkoutSessionFromEvent($type, $object),
            invoice: $this->invoiceFromEvent($type, $object),
        );
    }

    /**
     * @param array<string, mixed> $object
     */
    private function subscriptionFromEvent(string $type, array $object): ?GatewaySubscription
    {
        if (!str_starts_with($type, 'customer.subscription.')) {
            return null;
        }

        return $this->mapSubscription($object, $this->string($object, 'id') ?? '');
    }

    /**
     * @param array<string, mixed> $object
     */
    private function checkoutSessionFromEvent(string $type, array $object): ?GatewayCheckoutSession
    {
        if ('checkout.session.completed' !== $type) {
            return null;
        }

        $metadata = $this->map($object, 'metadata');

        return new GatewayCheckoutSession(
            externalId: $this->string($object, 'id') ?? '',
            status: $this->string($object, 'status') ?? 'open',
            subscriptionExternalId: $this->reference($object, 'subscription'),
            organizationId: $this->uuid($metadata, 'organization_id'),
            planId: $this->uuid($metadata, 'plan_id'),
            planName: $this->string($metadata, 'plan_name'),
            billingPeriod: $this->billingPeriod($metadata),
        );
    }

    /**
     * @param array<string, mixed> $object
     */
    private function invoiceFromEvent(string $type, array $object): ?GatewayInvoice
    {
        if (!str_starts_with($type, 'invoice.')) {
            return null;
        }

        $metadata = $this->map($object, 'metadata');
        $status = InvoiceStatus::tryFromGateway($this->string($object, 'status'));
        $currency = Currency::tryFromCode($this->string($object, 'currency'));

        if (null === $status || null === $currency) {
            return null;
        }

        return new GatewayInvoice(
            externalId: $this->string($object, 'id') ?? '',
            number: $this->string($object, 'number'),
            amountMinor: $this->invoiceAmount($object),
            currency: $currency,
            status: $status,
            issuedAt: $this->date($object, 'created') ?? new DateTimeImmutable(),
            paidAt: $this->invoicePaidAt($object),
            hostedUrl: $this->string($object, 'hosted_invoice_url'),
            pdfUrl: $this->string($object, 'invoice_pdf'),
            subscriptionExternalId: $this->invoiceSubscription($object),
            organizationId: $this->uuid($metadata, 'organization_id'),
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    private function mapSubscription(array $response, string $fallbackId): GatewaySubscription
    {
        $metadata = $this->map($response, 'metadata');

        $start = $this->date($response, 'current_period_start');
        $end = $this->date($response, 'current_period_end');

        // En las versiones nuevas de la API el periodo vive en el primer
        // elemento de `items`, no en la raíz. Se leen los dos sitios porque la
        // cuenta puede estar en cualquiera de las dos.
        if (null === $start || null === $end) {
            $item = $this->firstItem($response);
            $start ??= $this->date($item, 'current_period_start');
            $end ??= $this->date($item, 'current_period_end');
        }

        $start ??= new DateTimeImmutable();
        $end ??= $start->modify('+1 month');

        return new GatewaySubscription(
            externalId: $this->string($response, 'id') ?? $fallbackId,
            status: GatewaySubscriptionStatus::tryFromGateway($this->string($response, 'status'))
                ?? GatewaySubscriptionStatus::INCOMPLETE,
            currentPeriodStart: $start,
            currentPeriodEnd: $end,
            cancelAtPeriodEnd: true === ($response['cancel_at_period_end'] ?? false),
            canceledAt: $this->date($response, 'canceled_at'),
            organizationId: $this->uuid($metadata, 'organization_id'),
            planId: $this->uuid($metadata, 'plan_id'),
            planName: $this->string($metadata, 'plan_name'),
            billingPeriod: $this->billingPeriod($metadata),
            currency: Currency::tryFromCode($this->string($response, 'currency')),
        );
    }

    /**
     * @return array<string, string>
     */
    private function lineItem(CheckoutRequest $request): array
    {
        if (null !== $request->externalPriceId && '' !== trim($request->externalPriceId)) {
            return ['line_items[0][price]' => $request->externalPriceId];
        }

        [$interval, $count] = $this->interval($request->billingPeriod, $request->billingIntervalCount);

        return [
            'line_items[0][price_data][currency]' => strtolower($request->amount->currency->value),
            'line_items[0][price_data][unit_amount]' => (string) $request->amount->amountMinor,
            'line_items[0][price_data][recurring][interval]' => $interval,
            'line_items[0][price_data][recurring][interval_count]' => (string) $count,
            'line_items[0][price_data][product_data][name]' => $request->planName,
        ];
    }

    /**
     * Traduce el periodo de Suscripta al de Stripe.
     *
     * Stripe solo entiende `day`, `week`, `month` y `year` con un contador, así
     * que lo que no es un número entero de meses se expresa en días y lo demás
     * en meses. Un periodo anual es `year/1` y no `month/12`: es lo que hace
     * que Stripe cobre el 28 de octubre de cada año y no cada 365 días.
     *
     * @return array{0: string, 1: int}
     */
    private function interval(BillingPeriod $period, int $count): array
    {
        return match ($period) {
            BillingPeriod::WEEKLY => ['week', $count],
            BillingPeriod::ANNUAL => ['year', $count],
            BillingPeriod::BIENNIAL => ['year', 2 * $count],
            BillingPeriod::TRIENNIAL => ['year', 3 * $count],
            default => ['month', ($period->months() ?? 1) * $count],
        };
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function firstItem(array $response): array
    {
        $items = $this->map($response, 'items');
        $data = $items['data'] ?? null;

        if (!is_array($data) || [] === $data) {
            return [];
        }

        $first = $data[0] ?? [];

        return is_array($first) ? $first : [];
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private function invoiceAmount(array $invoice): int
    {
        $paid = $this->integer($invoice, 'amount_paid');

        if (null !== $paid && $paid > 0) {
            return $paid;
        }

        return $this->integer($invoice, 'amount_due')
            ?? $this->integer($invoice, 'total')
            ?? 0;
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private function invoicePaidAt(array $invoice): ?DateTimeImmutable
    {
        $transitions = $this->map($invoice, 'status_transitions');

        return $this->date($transitions, 'paid_at');
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private function invoiceSubscription(array $invoice): ?string
    {
        $direct = $this->reference($invoice, 'subscription');

        if (null !== $direct) {
            return $direct;
        }

        // En las versiones nuevas, la suscripción de una factura vive en
        // `parent.subscription_details.subscription`.
        $parent = $this->map($invoice, 'parent');
        $details = $this->map($parent, 'subscription_details');

        return $this->reference($details, 'subscription');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function billingPeriod(array $metadata): ?BillingPeriod
    {
        $value = $this->string($metadata, 'billing_period');

        return null === $value ? null : BillingPeriod::tryFrom($value);
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function post(string $operation, array $params, string $idempotencyKey): array
    {
        return $this->request('POST', $operation, $params, $idempotencyKey) ?? [];
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>|null
     */
    private function get(string $operation, array $params = []): ?array
    {
        $url = self::API_BASE.'/v1/'.$operation;

        if ([] !== $params) {
            $url .= '?'.http_build_query($params);
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $this->headers(),
                'timeout' => 20,
            ]);

            $status = $response->getStatusCode();

            if (404 === $status) {
                return null;
            }

            if ($status >= 400) {
                throw GatewayRequestException::failed($operation, $status, $this->errorDetail($response->getContent(false)));
            }

            return $this->decode($response->getContent(false), $operation);
        } catch (HttpClientException $exception) {
            throw GatewayRequestException::transport($operation, $exception->getMessage());
        }
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $operation, array $params, ?string $idempotencyKey): ?array
    {
        try {
            $response = $this->httpClient->request($method, self::API_BASE.'/v1/'.$operation, [
                'headers' => $this->headers() + (null === $idempotencyKey ? [] : ['Idempotency-Key' => $idempotencyKey]),
                'body' => http_build_query($params),
                'timeout' => 30,
            ]);

            $status = $response->getStatusCode();

            if (404 === $status) {
                return null;
            }

            if ($status >= 400) {
                throw GatewayRequestException::failed($operation, $status, $this->errorDetail($response->getContent(false)));
            }

            return $this->decode($response->getContent(false), $operation);
        } catch (HttpClientException $exception) {
            throw GatewayRequestException::transport($operation, $exception->getMessage());
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->secretKey(),
            'Stripe-Version' => self::API_VERSION,
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body, string $operation): array
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw GatewayRequestException::failed($operation, 200, 'la respuesta no es JSON');
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * El mensaje de error de Stripe, recortado.
     *
     * Se guarda solo `error.message`: el cuerpo entero puede llevar datos del
     * cliente y un log es el peor sitio donde dejarlos.
     */
    private function errorDetail(string $body): ?string
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return null;
        }

        $error = $decoded['error'] ?? null;

        if (!is_array($error)) {
            return null;
        }

        $message = $error['message'] ?? null;

        return is_string($message) ? substr($message, 0, 300) : null;
    }

    private function secretKey(bool $quiet = false): ?string
    {
        $key = $this->config->credential(IntegrationRegistry::KEY_STRIPE, 'secret_key');

        if (null === $key || '' === trim($key)) {
            if (!$quiet) {
                throw GatewayNotConfiguredException::missingSecretKey();
            }

            return null;
        }

        return trim($key);
    }

    private function webhookSecret(): string
    {
        $secret = $this->config->credential(IntegrationRegistry::KEY_STRIPE, 'webhook_secret');

        if (null === $secret || '' === trim($secret)) {
            $this->logger->error('Llega un webhook de Stripe y no hay secreto de firma configurado.');

            throw GatewayNotConfiguredException::missingWebhookSecret();
        }

        return trim($secret);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function map(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value)) {
            return [];
        }

        /* @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (is_string($value)) {
            return '' === trim($value) ? null : $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function integer(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function date(array $data, string $key): ?DateTimeImmutable
    {
        $value = $this->integer($data, $key);

        if (null === $value || $value <= 0) {
            return null;
        }

        return new DateTimeImmutable()->setTimestamp($value);
    }

    /**
     * Un campo que puede ser un identificador o un objeto expandido.
     *
     * Stripe devuelve `"sub_123"` o `{"id": "sub_123", …}` según se pida o no
     * el `expand`. Se aceptan las dos formas para que la consulta funcione con
     * y sin expansión.
     *
     * @param array<string, mixed> $data
     */
    private function reference(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (is_string($value)) {
            return '' === trim($value) ? null : $value;
        }

        if (is_array($value)) {
            $id = $value['id'] ?? null;

            return is_string($id) && '' !== trim($id) ? $id : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function uuid(array $data, string $key): ?Uuid
    {
        $value = $this->string($data, $key);

        if (null === $value) {
            return null;
        }

        try {
            return Uuid::fromString($value);
        } catch (Throwable) {
            $this->logger->warning('Se ignora un identificador de metadatos que no es un UUID.', ['field' => $key]);

            return null;
        }
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private function flatten(string $prefix, array $values): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $flat[sprintf('%s[%s]', $prefix, $key)] = $value;
        }

        return array_filter($flat, static fn (string $value): bool => '' !== $value);
    }
}
