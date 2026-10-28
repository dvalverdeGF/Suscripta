<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing\Infrastructure\Stripe;

use App\Billing\Domain\Enum\GatewaySubscriptionStatus;
use App\Billing\Domain\Exception\GatewayNotConfiguredException;
use App\Billing\Domain\ValueObject\CheckoutRequest;
use App\Billing\Infrastructure\Stripe\StripeGateway;
use App\Billing\Infrastructure\Stripe\StripeSignatureVerifier;
use App\Integrations\Application\IntegrationConfigReader;
use App\Integrations\Application\IntegrationConfigWriter;
use App\Integrations\Application\IntegrationRegistry;
use App\Mailbox\Infrastructure\Crypto\SodiumCredentialCipher;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Integrations\InMemoryIntegrationRepository;

use function array_pad;
use function explode;
use function hash_hmac;
use function json_encode;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function sprintf;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

use function time;
use function urldecode;

/**
 * El cliente de Stripe sin el SDK (Fase 24).
 *
 * Se prueba contra `MockHttpClient` y no contra Stripe: lo que puede romperse
 * aquí no es la red, es **el cuerpo que se envía** y **cómo se lee lo que
 * vuelve**. Un `price_data` mal formado o un periodo leído de donde ya no está
 * son fallos silenciosos —el checkout se abre y cobra otra cosa— y solo se
 * detectan mirando la petición.
 */
#[CoversClass(StripeGateway::class)]
final class StripeGatewayTest extends TestCase
{
    private const string WEBHOOK_SECRET = 'whsec_prueba';

    private InMemoryIntegrationRepository $repository;

    /**
     * @var array{method: string, url: string, options: array<string, mixed>}|null
     */
    private ?array $lastRequest = null;

    protected function setUp(): void
    {
        $this->repository = new InMemoryIntegrationRepository();
    }

    public function testItIsNotConfiguredUntilThereIsASecretKey(): void
    {
        self::assertFalse($this->gateway()->isConfigured());

        $this->saveStripe(secretKey: 'sk_test_123');

        self::assertTrue($this->gateway()->isConfigured());
    }

    public function testItSendsAPriceBuiltOnTheFlyWhenThereIsNoStripePrice(): void
    {
        $this->saveStripe();

        $gateway = $this->gateway([new MockResponse(json_encode(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/cs_1'], JSON_THROW_ON_ERROR))]);

        $session = $gateway->createCheckoutSession($this->checkoutRequest());

        self::assertSame('cs_1', $session->externalId);
        self::assertSame('https://checkout.stripe.com/cs_1', $session->url);

        $body = $this->sentBody();

        self::assertSame('subscription', $body['mode']);
        self::assertSame('2900', $body['line_items[0][price_data][unit_amount]']);
        self::assertSame('eur', $body['line_items[0][price_data][currency]']);
        self::assertSame('month', $body['line_items[0][price_data][recurring][interval]']);
        self::assertSame('1', $body['line_items[0][price_data][recurring][interval_count]']);
        self::assertSame('Plan Pro', $body['line_items[0][price_data][product_data][name]']);
        self::assertArrayNotHasKey('line_items[0][price]', $body);
    }

    public function testItSendsAnAnnualPlanAsAYearlyPrice(): void
    {
        $this->saveStripe();

        $gateway = $this->gateway([new MockResponse('{"id":"cs_1","url":"https://checkout.stripe.com/cs_1"}')]);

        $gateway->createCheckoutSession($this->checkoutRequest(period: BillingPeriod::ANNUAL, amount: 29_000));

        $body = $this->sentBody();

        // Anual es `year/1`, no `month/12`: así Stripe cobra el mismo día del
        // año siguiente en vez de cada 365 días.
        self::assertSame('year', $body['line_items[0][price_data][recurring][interval]']);
        self::assertSame('1', $body['line_items[0][price_data][recurring][interval_count]']);
    }

    public function testItUsesTheStripePriceWhenTheOperatorLinkedOne(): void
    {
        $this->saveStripe();

        $gateway = $this->gateway([new MockResponse('{"id":"cs_1","url":"https://checkout.stripe.com/cs_1"}')]);

        $gateway->createCheckoutSession($this->checkoutRequest(externalPriceId: 'price_123'));

        $body = $this->sentBody();

        self::assertSame('price_123', $body['line_items[0][price]']);
        self::assertArrayNotHasKey('line_items[0][price_data][unit_amount]', $body);
    }

    public function testTheOrganizationTravelsInTheSessionAndInTheSubscription(): void
    {
        $this->saveStripe();

        $request = $this->checkoutRequest();
        $gateway = $this->gateway([new MockResponse('{"id":"cs_1","url":"https://checkout.stripe.com/cs_1"}')]);

        $gateway->createCheckoutSession($request);

        $body = $this->sentBody();

        self::assertSame($request->organizationId->toRfc4122(), $body['client_reference_id']);
        self::assertSame($request->organizationId->toRfc4122(), $body['metadata[organization_id]']);
        self::assertSame($request->planId->toRfc4122(), $body['metadata[plan_id]']);
        self::assertSame('monthly', $body['metadata[billing_period]']);

        // Sin los metadatos en la suscripción, un `customer.subscription.updated`
        // que llegue suelto no sabría a qué organización pertenece.
        self::assertSame($request->organizationId->toRfc4122(), $body['subscription_data[metadata][organization_id]']);
    }

    public function testItReadsThePeriodFromTheRootOfTheSubscription(): void
    {
        $this->saveStripe();

        $gateway = $this->gateway([new MockResponse(json_encode([
            'id' => 'sub_1',
            'status' => 'active',
            'current_period_start' => 1_700_000_000,
            'current_period_end' => 1_702_678_400,
            'currency' => 'EUR',
            'metadata' => ['organization_id' => Uuid::v7()->toRfc4122()],
        ], JSON_THROW_ON_ERROR))]);

        $subscription = $gateway->findSubscription('sub_1');

        self::assertNotNull($subscription);
        self::assertSame(GatewaySubscriptionStatus::ACTIVE, $subscription->status);
        self::assertSame('2023-11-14', $subscription->currentPeriodStart->format('Y-m-d'));
        self::assertSame(Currency::EUR, $subscription->currency);
    }

    public function testItReadsThePeriodFromTheFirstItemWhenTheRootNoLongerHasIt(): void
    {
        $this->saveStripe();

        $gateway = $this->gateway([new MockResponse(json_encode([
            'id' => 'sub_1',
            'status' => 'active',
            'items' => ['data' => [[
                'current_period_start' => 1_700_000_000,
                'current_period_end' => 1_702_678_400,
            ]]],
        ], JSON_THROW_ON_ERROR))]);

        $subscription = $gateway->findSubscription('sub_1');

        self::assertNotNull($subscription);
        self::assertSame('2023-11-14', $subscription->currentPeriodStart->format('Y-m-d'));
    }

    public function testAMissingSubscriptionIsNotAnError(): void
    {
        $this->saveStripe();

        $gateway = $this->gateway([new MockResponse('{"error":{"message":"No such subscription"}}', ['http_code' => 404])]);

        self::assertNull($gateway->findSubscription('sub_que_no_existe'));
    }

    public function testTheCheckoutSessionCarriesWhatTheReturnNeeds(): void
    {
        $this->saveStripe();

        $organizationId = Uuid::v7();
        $planId = Uuid::v7();

        $gateway = $this->gateway([new MockResponse(json_encode([
            'id' => 'cs_1',
            'status' => 'complete',
            'subscription' => 'sub_1',
            'metadata' => [
                'organization_id' => $organizationId->toRfc4122(),
                'plan_id' => $planId->toRfc4122(),
                'plan_name' => 'Plan Pro',
                'billing_period' => 'annual',
            ],
        ], JSON_THROW_ON_ERROR))]);

        $session = $gateway->findCheckoutSession('cs_1');

        self::assertNotNull($session);
        self::assertSame('complete', $session->status);
        self::assertSame('sub_1', $session->subscriptionExternalId);
        self::assertSame($organizationId->toRfc4122(), $session->organizationId?->toRfc4122());
        self::assertSame($planId->toRfc4122(), $session->planId?->toRfc4122());
        self::assertSame(BillingPeriod::ANNUAL, $session->billingPeriod);
    }

    public function testItParsesASignedInvoiceEvent(): void
    {
        $this->saveStripe();

        $payload = json_encode([
            'id' => 'evt_1',
            'type' => 'invoice.paid',
            'created' => 1_700_000_000,
            'data' => ['object' => [
                'id' => 'in_1',
                'number' => 'SUS-0001',
                'amount_paid' => 2900,
                'currency' => 'eur',
                'status' => 'paid',
                'created' => 1_700_000_000,
                'status_transitions' => ['paid_at' => 1_700_000_100],
                'hosted_invoice_url' => 'https://invoice.stripe.com/in_1',
                'invoice_pdf' => 'https://invoice.stripe.com/in_1.pdf',
                'subscription' => 'sub_1',
            ]],
        ], JSON_THROW_ON_ERROR);

        $event = $this->gateway()->parseEvent($payload, $this->sign($payload));

        self::assertSame('evt_1', $event->id);
        self::assertSame('invoice.paid', $event->type);
        self::assertNull($event->subscription);

        $invoice = $event->invoice;

        self::assertNotNull($invoice);
        self::assertSame('in_1', $invoice->externalId);
        self::assertSame('SUS-0001', $invoice->number);
        self::assertSame(2900, $invoice->amountMinor);
        self::assertSame(Currency::EUR, $invoice->currency);
        self::assertSame('sub_1', $invoice->subscriptionExternalId);
        self::assertNotNull($invoice->paidAt);
    }

    public function testItParsesASubscriptionEventWithTheOrganizationInTheMetadata(): void
    {
        $this->saveStripe();

        $organizationId = Uuid::v7();

        $payload = json_encode([
            'id' => 'evt_2',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_1',
                'status' => 'past_due',
                'current_period_start' => 1_700_000_000,
                'current_period_end' => 1_702_678_400,
                'metadata' => ['organization_id' => $organizationId->toRfc4122()],
            ]],
        ], JSON_THROW_ON_ERROR);

        $event = $this->gateway()->parseEvent($payload, $this->sign($payload));

        $subscription = $event->subscription;

        self::assertNotNull($subscription);
        self::assertSame(GatewaySubscriptionStatus::PAST_DUE, $subscription->status);
        self::assertSame($organizationId->toRfc4122(), $subscription->organizationId?->toRfc4122());
    }

    public function testAnUnsignedEventIsRejectedBeforeLookingAtTheBody(): void
    {
        $this->saveStripe();

        $this->expectException(\App\Billing\Domain\Exception\InvalidSignatureException::class);

        $this->gateway()->parseEvent('{"id":"evt_1","type":"invoice.paid"}', 't=1,v1=abc');
    }

    public function testAnEventCannotBeReadWithoutTheWebhookSecret(): void
    {
        $this->saveStripe(webhookSecret: null);

        $this->expectException(GatewayNotConfiguredException::class);

        $this->gateway()->parseEvent('{"id":"evt_1"}', 't=1,v1=abc');
    }

    public function testTheSecretKeyIsRequiredToTalkToStripe(): void
    {
        $this->expectException(GatewayNotConfiguredException::class);

        $this->gateway()->createCheckoutSession($this->checkoutRequest());
    }

    private function checkoutRequest(
        BillingPeriod $period = BillingPeriod::MONTHLY,
        int $amount = 2900,
        ?string $externalPriceId = null,
    ): CheckoutRequest {
        return new CheckoutRequest(
            organizationId: Uuid::v7(),
            planId: Uuid::v7(),
            planName: 'Plan Pro',
            amount: Money::of($amount, Currency::EUR),
            billingPeriod: $period,
            billingIntervalCount: 1,
            externalPriceId: $externalPriceId,
            customerEmail: 'ada@example.com',
            successUrl: 'https://suscripta.app/checkout/gracias?session_id={CHECKOUT_SESSION_ID}',
            cancelUrl: 'https://suscripta.app/precios',
        );
    }

    private function saveStripe(?string $secretKey = 'sk_test_123', ?string $webhookSecret = self::WEBHOOK_SECRET): void
    {
        $cipher = new SodiumCredentialCipher('secreto-de-prueba-para-el-cifrador');

        $writer = new IntegrationConfigWriter(
            repository: $this->repository,
            credentialCipher: $cipher,
            tenantContext: $this->tenantContext(),
            logger: new NullLogger(),
        );

        $writer->save(
            key: IntegrationRegistry::KEY_STRIPE,
            label: 'Stripe',
            baseUrl: null,
            credentials: array_filter(['secret_key' => $secretKey, 'webhook_secret' => $webhookSecret], static fn (?string $value): bool => null !== $value),
            settings: [],
            enabled: true,
        );
    }

    private function tenantContext(): TenantContext
    {
        $tenantContext = new TenantContext();
        $tenantContext->setOrganizationId(Uuid::v7());

        return $tenantContext;
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function gateway(array $responses = []): StripeGateway
    {
        $cipher = new SodiumCredentialCipher('secreto-de-prueba-para-el-cifrador');

        $reader = new IntegrationConfigReader(
            repository: $this->repository,
            registry: new IntegrationRegistry(),
            credentialCipher: $cipher,
            logger: new NullLogger(),
        );

        return new StripeGateway(
            httpClient: $this->httpClient($responses),
            config: $reader,
            verifier: new StripeSignatureVerifier(tolerance: 300),
            logger: new NullLogger(),
        );
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function httpClient(array $responses): MockHttpClient
    {
        $this->lastRequest = null;

        return new MockHttpClient(function (string $method, string $url, array $options = []) use ($responses): MockResponse {
            $this->lastRequest = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}');
        });
    }

    /**
     * @return array<string, string>
     */
    private function sentBody(): array
    {
        self::assertNotNull($this->lastRequest, 'No se ha enviado ninguna petición.');

        // No se usa `parse_str`: convierte `line_items[0][price_data]` en un
        // array anidado y aquí lo que se comprueba es la clave tal cual viaja.
        $body = [];

        foreach (explode('&', (string) ($this->lastRequest['options']['body'] ?? '')) as $pair) {
            if ('' === $pair) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            $body[urldecode($key)] = urldecode($value);
        }

        return $body;
    }

    private function sign(string $payload): string
    {
        $timestamp = time();

        return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, self::WEBHOOK_SECRET));
    }
}
