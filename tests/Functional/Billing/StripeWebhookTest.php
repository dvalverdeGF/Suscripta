<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\Billing\Domain\Enum\GatewaySubscriptionStatus;
use App\Billing\Domain\Enum\InvoiceStatus;
use App\Billing\Domain\Port\PaymentGatewayInterface;
use App\Billing\Domain\Repository\PaymentEventRepositoryInterface;
use App\Billing\Domain\Repository\SalesInvoiceRepositoryInterface;
use App\Billing\Domain\ValueObject\GatewayInvoice;
use App\Billing\Domain\ValueObject\GatewaySubscription;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Enum\OrganizationPlan;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Subscriptions\Domain\Entity\Plan;
use App\Subscriptions\Domain\Enum\SubscriptionStatus;
use App\Subscriptions\Domain\Repository\PlanRepositoryInterface;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use App\Tests\Support\Billing\FakeStripeGateway;
use DateTimeImmutable;

use function json_encode;

use const JSON_THROW_ON_ERROR;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * El webhook de la pasarela, por HTTP (Fase 24).
 *
 * ## Lo que se comprueba
 *
 * 1. **Sin firma válida no pasa nada.** Ni se aplica el evento ni se toca la
 *    base de datos: 400.
 * 2. **Un evento se aplica una sola vez.** El mismo `id` dos veces deja una
 *    suscripción, no dos cobros. Es lo que evita que un reintento de Stripe
 *    (que reintenta durante días) duplique altas.
 * 3. **Lo que no se entiende se contesta 200.** Un tipo desconocido se apunta
 *    como ignorado; devolver error lo haría reintentar sin fin.
 *
 * La verificación de la firma real tiene su propio test unitario; aquí el
 * doble dice «firma mal» o «firma bien» y lo que se prueba es la reacción.
 */
final class StripeWebhookTest extends WebTestCase
{
    private KernelBrowser $client;
    private Uuid $organizationId;
    private Plan $plan;
    private FakeStripeGateway $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');

        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($user);
        self::assertNotEmpty($memberships);
        $this->organizationId = $memberships[0]['organization']->getId();
        $this->plan = new Plan(OrganizationPlan::PRO, 'Plan Pro', 1);
        $this->plan->setActive(true);
        self::getContainer()->get(PlanRepositoryInterface::class)->save($this->plan);

        $gateway = self::getContainer()->get(PaymentGatewayInterface::class);
        self::assertInstanceOf(FakeStripeGateway::class, $gateway);
        $this->gateway = $gateway;
    }

    public function testAnUnsignedEventIsRejectedWithBadRequest(): void
    {
        $this->gateway->rejectSignatures = true;

        $this->post(['id' => 'evt_1', 'type' => 'customer.subscription.created']);

        self::assertResponseStatusCodeSame(400);

        // Ni siquiera se reserva el evento: la firma se comprueba antes de
        // mirar el cuerpo.
        self::assertNull(self::getContainer()->get(PaymentEventRepositoryInterface::class)->find('evt_1'));
    }

    public function testAWebhookWithoutSecretSaysServiceUnavailable(): void
    {
        $this->gateway->webhookSecretConfigured = false;

        $this->post(['id' => 'evt_1', 'type' => 'customer.subscription.created']);

        self::assertResponseStatusCodeSame(503);
    }

    public function testASubscriptionEventCreatesTheSubscriptionOfTheOrganization(): void
    {
        $this->gateway->withSubscription($this->remoteSubscription('sub_1', GatewaySubscriptionStatus::ACTIVE));

        $this->post(['id' => 'evt_1', 'type' => 'customer.subscription.created', 'subscription' => 'sub_1']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ok:applied', (string) $this->client->getResponse()->getContent());

        $subscription = self::getContainer()->get(SubscriptionRepositoryInterface::class)
            ->findByExternalReference('sub_1');

        self::assertNotNull($subscription);
        self::assertSame($this->organizationId->toRfc4122(), $subscription->getOrganizationId()->toRfc4122());
        self::assertSame(SubscriptionStatus::ACTIVE, $subscription->getStatus());
        self::assertSame($this->plan->getId()->toRfc4122(), $subscription->getPlanId()?->toRfc4122());
    }

    public function testTheSameEventTwiceDoesNotCreateTwoSubscriptions(): void
    {
        $this->gateway->withSubscription($this->remoteSubscription('sub_1', GatewaySubscriptionStatus::ACTIVE));

        $payload = ['id' => 'evt_1', 'type' => 'customer.subscription.created', 'subscription' => 'sub_1'];

        $this->post($payload);
        self::assertResponseIsSuccessful();

        $this->post($payload);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ok:duplicate', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, self::getContainer()->get(SubscriptionRepositoryInterface::class)->findForOrganization());
    }

    public function testAnUnknownEventTypeIsAnsweredOk(): void
    {
        $this->post(['id' => 'evt_1', 'type' => 'customer.discount.created']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ok:ignored', (string) $this->client->getResponse()->getContent());
    }

    public function testAnInvoiceEventStoresTheInvoiceAndRenewsThePeriod(): void
    {
        $this->gateway->withSubscription($this->remoteSubscription('sub_1', GatewaySubscriptionStatus::ACTIVE));

        $this->gateway->withInvoice(new GatewayInvoice(
            externalId: 'in_1',
            number: 'SUS-2026-0001',
            amountMinor: 2900,
            currency: Currency::EUR,
            status: InvoiceStatus::PAID,
            issuedAt: new DateTimeImmutable('-1 minute'),
            paidAt: new DateTimeImmutable('-1 minute'),
            hostedUrl: 'https://invoice.stripe.test/in_1',
            pdfUrl: 'https://invoice.stripe.test/in_1.pdf',
            subscriptionExternalId: 'sub_1',
            organizationId: $this->organizationId,
        ));

        $this->post(['id' => 'evt_1', 'type' => 'invoice.paid', 'invoice' => 'in_1']);

        self::assertResponseIsSuccessful();

        $invoice = self::getContainer()->get(SalesInvoiceRepositoryInterface::class)->findByExternalId('in_1');

        self::assertNotNull($invoice);
        self::assertSame(2900, $invoice->getAmount()->amountMinor);
        self::assertSame(InvoiceStatus::PAID, $invoice->getStatus());

        // El periodo lo dicta la pasarela: la factura no se limita a apuntar el
        // cobro, vuelve a sincronizar la suscripción.
        self::assertNotNull(self::getContainer()->get(SubscriptionRepositoryInterface::class)->findByExternalReference('sub_1'));
    }

    public function testAnInvoiceWithoutOrganizationIsIgnoredInsteadOfGuessed(): void
    {
        $this->gateway->withInvoice(new GatewayInvoice(
            externalId: 'in_suelta',
            number: null,
            amountMinor: 2900,
            currency: Currency::EUR,
            status: InvoiceStatus::PAID,
            issuedAt: new DateTimeImmutable(),
        ));

        $this->post(['id' => 'evt_1', 'type' => 'invoice.paid', 'invoice' => 'in_suelta']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ok:ignored', (string) $this->client->getResponse()->getContent());
        self::assertNull(self::getContainer()->get(SalesInvoiceRepositoryInterface::class)->findByExternalId('in_suelta'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(array $payload): void
    {
        $this->client->request(
            'POST',
            '/webhooks/stripe',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=firma'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function remoteSubscription(string $externalId, GatewaySubscriptionStatus $status): GatewaySubscription
    {
        return new GatewaySubscription(
            externalId: $externalId,
            status: $status,
            currentPeriodStart: new DateTimeImmutable('-1 day'),
            currentPeriodEnd: new DateTimeImmutable('+29 days'),
            organizationId: $this->organizationId,
            planId: $this->plan->getId(),
            planName: 'Plan Pro',
            billingPeriod: BillingPeriod::MONTHLY,
            currency: Currency::EUR,
        );
    }
}
