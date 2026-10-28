<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\Billing\Domain\Port\PaymentGatewayInterface;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Enum\OrganizationPlan;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use App\Subscriptions\Domain\Entity\Plan;
use App\Subscriptions\Domain\Entity\PlanPrice;
use App\Subscriptions\Domain\Entity\Subscription;
use App\Subscriptions\Domain\Enum\SubscriptionStatus;
use App\Subscriptions\Domain\Repository\PlanPriceRepositoryInterface;
use App\Subscriptions\Domain\Repository\PlanRepositoryInterface;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use App\Tests\Support\Billing\FakeStripeGateway;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Contratar un plan, por HTTP (Fase 24).
 *
 * ## Lo que se prueba no es «redirige a Stripe»
 *
 * Se prueba que **no** se manda a nadie a pagar cuando no se debe: un precio
 * histórico, un plan retirado, un plan gratuito, una suscripción que ya existe
 * o una pasarela sin configurar. Cada uno de esos casos, si se colara, acaba en
 * un cobro doble o en una pantalla de error de Stripe. El camino feliz es una
 * sola comprobación: que el cliente acaba en la URL que devolvió la pasarela.
 *
 * ## La pasarela es un doble
 *
 * `FakeStripeGateway` implementa el mismo puerto, así que el controlador y el
 * servicio de aplicación se ejecutan de verdad; lo único falso es la red.
 */
final class CheckoutTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private Uuid $organizationId;
    private FakeStripeGateway $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');

        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($this->user);
        self::assertNotEmpty($memberships);
        $this->organizationId = $memberships[0]['organization']->getId();

        $gateway = self::getContainer()->get(PaymentGatewayInterface::class);
        self::assertInstanceOf(FakeStripeGateway::class, $gateway);
        $this->gateway = $gateway;
    }

    public function testACustomerWithoutSessionCannotOpenACheckout(): void
    {
        $price = $this->createPrice();

        $this->client->request('GET', '/checkout/'.$price->getId()->toRfc4122());

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testItSendsTheCustomerToTheGatewayWithThePriceOfTheCatalog(): void
    {
        $this->signIn();

        $price = $this->createPrice(monthly: 2900);

        $this->client->request('GET', '/checkout/'.$price->getId()->toRfc4122());

        self::assertResponseRedirects('https://checkout.stripe.test/session');

        self::assertCount(1, $this->gateway->checkoutRequests);

        $request = $this->gateway->checkoutRequests[0];

        self::assertSame($this->organizationId->toRfc4122(), $request->organizationId->toRfc4122());
        self::assertSame(2900, $request->amount->amountMinor);
        self::assertSame(BillingPeriod::MONTHLY, $request->billingPeriod);
        self::assertSame('ada@example.com', $request->customerEmail);
        self::assertSame('Plan Pro', $request->planName);
    }

    public function testAHistoricalPriceIsNotSold(): void
    {
        $this->signIn();

        $price = $this->createPrice(validFrom: new DateTimeImmutable('-1 year'));
        $price->close(new DateTimeImmutable('-1 day'));
        self::getContainer()->get(PlanPriceRepositoryInterface::class)->save($price);

        $this->client->request('GET', '/checkout/'.$price->getId()->toRfc4122());

        self::assertResponseRedirects('/precios');
        self::assertCount(0, $this->gateway->checkoutRequests);
    }

    public function testARetiredPlanIsNotSold(): void
    {
        $this->signIn();

        $price = $this->createPrice();
        $price->getPlan()->setActive(false);
        self::getContainer()->get(PlanRepositoryInterface::class)->save($price->getPlan());

        $this->client->request('GET', '/checkout/'.$price->getId()->toRfc4122());

        self::assertResponseRedirects('/precios');
        self::assertCount(0, $this->gateway->checkoutRequests);
    }

    public function testTheFreePlanIsNotSold(): void
    {
        $this->signIn();

        $price = $this->createPrice(monthly: 0);

        $this->client->request('GET', '/checkout/'.$price->getId()->toRfc4122());

        self::assertResponseRedirects('/precios');
        self::assertCount(0, $this->gateway->checkoutRequests);
    }

    public function testItDoesNotChargeTwiceForTheSamePlan(): void
    {
        $this->signIn();

        $price = $this->createPrice();

        $subscription = new Subscription(
            organizationId: $this->organizationId,
            name: 'Plan Pro',
            currency: Currency::EUR,
            billingPeriod: BillingPeriod::MONTHLY,
            billingIntervalCount: 1,
            startedAt: new DateTimeImmutable('-1 month'),
            status: SubscriptionStatus::ACTIVE,
            createdByUserId: null,
        );

        $subscription->changePlan($price->getPlan()->getId(), 'Plan Pro');

        self::getContainer()->get(SubscriptionRepositoryInterface::class)->save($subscription);

        $this->client->request('GET', '/checkout/'.$price->getId()->toRfc4122());

        self::assertResponseRedirects('/precios');
        self::assertCount(0, $this->gateway->checkoutRequests);
    }

    public function testItSaysSoWhenTheGatewayIsNotConfigured(): void
    {
        $this->signIn();

        $this->gateway->configured = false;

        $price = $this->createPrice();

        $this->client->request('GET', '/checkout/'.$price->getId()->toRfc4122());

        self::assertResponseRedirects('/precios');
        self::assertCount(0, $this->gateway->checkoutRequests);

        $this->client->followRedirect();

        self::assertSelectorExists('.notice');
    }

    public function testAnUnknownPriceIsANotFound(): void
    {
        $this->signIn();

        $this->client->request('GET', '/checkout/'.Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }

    private function signIn(): void
    {
        $this->client->loginUser($this->user);
    }

    private function createPrice(int $monthly = 2900, ?DateTimeImmutable $validFrom = null): PlanPrice
    {
        $plan = new Plan(OrganizationPlan::PRO, 'Plan Pro', 1);
        $plan->setActive(true);

        self::getContainer()->get(PlanRepositoryInterface::class)->save($plan);

        $price = new PlanPrice($plan, Money::of($monthly, Currency::EUR), BillingPeriod::MONTHLY, $validFrom);

        self::getContainer()->get(PlanPriceRepositoryInterface::class)->save($price);

        return $price;
    }
}
