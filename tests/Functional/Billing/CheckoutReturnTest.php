<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\Billing\Domain\Enum\GatewaySubscriptionStatus;
use App\Billing\Domain\Port\PaymentGatewayInterface;
use App\Billing\Domain\ValueObject\GatewayCheckoutSession;
use App\Billing\Domain\ValueObject\GatewaySubscription;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use App\Tests\Support\Billing\FakeStripeGateway;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * La vuelta del pago, por HTTP (Fase 24).
 *
 * ## Lo que se prueba
 *
 * Que el cliente que acaba de pagar vea su plan activo **sin esperar al
 * webhook**, y que la pantalla no afirme que se ha cobrado cuando no consta.
 * Las dos cosas importan: la primera es la razón de consultar la sesión al
 * volver; la segunda es no mentir a quien viene de pagar.
 *
 * También se comprueba que un `session_id` de otra organización pegado en la
 * URL no activa nada: es una comprobación de propiedad, no de existencia.
 */
final class CheckoutReturnTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private Uuid $organizationId;
    private FakeStripeGateway $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');

        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($this->user);
        self::assertNotEmpty($memberships);
        $this->organizationId = $memberships[0]['organization']->getId();

        $gateway = self::getContainer()->get(PaymentGatewayInterface::class);
        self::assertInstanceOf(FakeStripeGateway::class, $gateway);
        $this->gateway = $gateway;
    }

    public function testACompleteSessionActivatesThePlanBeforeTheWebhookArrives(): void
    {
        $this->signIn();

        $this->gateway->withCheckoutSession($this->session('cs_1', $this->organizationId, 'sub_1'));
        $this->gateway->withSubscription($this->remoteSubscription('sub_1'));

        $this->client->request('GET', '/checkout/gracias?session_id=cs_1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-empty__title', 'Pago confirmado');

        $subscription = self::getContainer()->get(SubscriptionRepositoryInterface::class)->findByExternalReference('sub_1');

        self::assertNotNull($subscription);
        self::assertSame($this->organizationId->toRfc4122(), $subscription->getOrganizationId()->toRfc4122());
    }

    public function testASessionThatIsNotCompleteSaysThePaymentIsBeingConfirmed(): void
    {
        $this->signIn();

        $this->gateway->withCheckoutSession(new GatewayCheckoutSession(
            externalId: 'cs_1',
            status: 'open',
            subscriptionExternalId: null,
            organizationId: $this->organizationId,
            planId: null,
            planName: 'Plan Pro',
            billingPeriod: BillingPeriod::MONTHLY,
        ));

        $this->client->request('GET', '/checkout/gracias?session_id=cs_1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-empty__title', 'Estamos confirmando el pago');
    }

    public function testASessionOfAnotherOrganizationActivatesNothing(): void
    {
        $this->signIn();

        $this->gateway->withCheckoutSession($this->session('cs_ajena', Uuid::v7(), 'sub_ajena'));
        $this->gateway->withSubscription($this->remoteSubscription('sub_ajena'));

        $this->client->request('GET', '/checkout/gracias?session_id=cs_ajena');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-empty__title', 'Estamos confirmando el pago');
        self::assertNull(self::getContainer()->get(SubscriptionRepositoryInterface::class)->findByExternalReference('sub_ajena'));
    }

    public function testReturningWithoutASessionIdDoesNotClaimAnything(): void
    {
        $this->signIn();

        $this->client->request('GET', '/checkout/gracias');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-empty__title', 'Estamos confirmando el pago');
    }

    private function signIn(): void
    {
        $this->client->loginUser($this->user);
    }

    private function session(string $externalId, Uuid $organizationId, string $subscriptionId): GatewayCheckoutSession
    {
        return new GatewayCheckoutSession(
            externalId: $externalId,
            status: 'complete',
            subscriptionExternalId: $subscriptionId,
            organizationId: $organizationId,
            planId: null,
            planName: 'Plan Pro',
            billingPeriod: BillingPeriod::MONTHLY,
        );
    }

    private function remoteSubscription(string $externalId): GatewaySubscription
    {
        return new GatewaySubscription(
            externalId: $externalId,
            status: GatewaySubscriptionStatus::ACTIVE,
            currentPeriodStart: new DateTimeImmutable('-1 minute'),
            currentPeriodEnd: new DateTimeImmutable('+1 month'),
            organizationId: $this->organizationId,
            planName: 'Plan Pro',
            billingPeriod: BillingPeriod::MONTHLY,
            currency: Currency::EUR,
        );
    }
}
