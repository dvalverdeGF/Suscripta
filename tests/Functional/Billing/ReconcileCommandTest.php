<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\Billing\Domain\Enum\GatewaySubscriptionStatus;
use App\Billing\Domain\Port\PaymentGatewayInterface;
use App\Billing\Domain\ValueObject\GatewaySubscription;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Subscriptions\Domain\Entity\Subscription;
use App\Subscriptions\Domain\Enum\SubscriptionStatus;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use App\Tests\Support\Billing\FakeStripeGateway;
use DateTimeImmutable;

use function str_contains;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * La conciliación nocturna con la pasarela (Fase 24).
 *
 * ## Qué se prueba
 *
 * Que el comando ponga al día una suscripción que se quedó atrás porque el
 * webhook se perdió, que **no cancele** lo que la pasarela no conoce y que no
 * falle cuando no hay nada configurado. Esos tres comportamientos son la razón
 * de ser del comando; si el segundo se rompiera, un fallo puntual de red daría
 * de baja a clientes que están pagando.
 */
final class ReconcileCommandTest extends KernelTestCase
{
    private Uuid $organizationId;
    private FakeStripeGateway $gateway;

    protected function setUp(): void
    {
        self::bootKernel();

        $user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');

        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($user);
        self::assertNotEmpty($memberships);
        $this->organizationId = $memberships[0]['organization']->getId();

        $gateway = self::getContainer()->get(PaymentGatewayInterface::class);
        self::assertInstanceOf(FakeStripeGateway::class, $gateway);
        $this->gateway = $gateway;
    }

    public function testItSaysThereIsNothingToReconcileWhenNoSubscriptionIsBilled(): void
    {
        $tester = $this->executeReconcile();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No hay ninguna suscripción', $tester->getDisplay());
    }

    public function testItUpdatesASubscriptionThatFellBehind(): void
    {
        $subscription = $this->storeSubscription('sub_1', SubscriptionStatus::ACTIVE);
        $subscription->syncWithGateway(
            status: SubscriptionStatus::ACTIVE,
            currentPeriodStart: new DateTimeImmutable('-60 days'),
            currentPeriodEnd: new DateTimeImmutable('-30 days'),
            externalReference: 'sub_1',
        );

        $this->gateway->withSubscription(new GatewaySubscription(
            externalId: 'sub_1',
            status: GatewaySubscriptionStatus::PAST_DUE,
            currentPeriodStart: new DateTimeImmutable('-1 day'),
            currentPeriodEnd: new DateTimeImmutable('+29 days'),
            organizationId: $this->organizationId,
            currency: Currency::EUR,
        ));

        $tester = $this->executeReconcile();

        self::assertSame(0, $tester->getStatusCode());
        self::assertTrue(str_contains($tester->getDisplay(), '1 suscripciones revisadas, 1 actualizadas'));

        $refreshed = self::getContainer()->get(SubscriptionRepositoryInterface::class)->findByExternalReference('sub_1');

        self::assertNotNull($refreshed);
        self::assertSame(SubscriptionStatus::PAST_DUE, $refreshed->getStatus());
    }

    public function testItDoesNotCancelASubscriptionTheGatewayNoLongerKnows(): void
    {
        $this->storeSubscription('sub_fantasma', SubscriptionStatus::ACTIVE);

        $tester = $this->executeReconcile();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('la pasarela no conoce', $tester->getDisplay());

        $subscription = self::getContainer()->get(SubscriptionRepositoryInterface::class)
            ->findByExternalReference('sub_fantasma');

        self::assertNotNull($subscription);
        self::assertSame(SubscriptionStatus::ACTIVE, $subscription->getStatus());
    }

    public function testItDoesNotFailWhenTheGatewayIsNotConfigured(): void
    {
        $this->storeSubscription('sub_1', SubscriptionStatus::ACTIVE);
        $this->gateway->configured = false;

        $tester = $this->executeReconcile();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No hay ninguna suscripción', $tester->getDisplay());
    }

    private function executeReconcile(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:billing:reconcile'));
        $tester->execute([]);

        return $tester;
    }

    private function storeSubscription(string $externalReference, SubscriptionStatus $status): Subscription
    {
        $subscription = new Subscription(
            organizationId: $this->organizationId,
            name: 'Plan Pro',
            currency: Currency::EUR,
            billingPeriod: BillingPeriod::MONTHLY,
            billingIntervalCount: 1,
            startedAt: new DateTimeImmutable('-30 days'),
            status: $status,
            createdByUserId: null,
        );

        $subscription->syncWithGateway(
            status: $status,
            currentPeriodStart: new DateTimeImmutable('-30 days'),
            currentPeriodEnd: new DateTimeImmutable('-1 day'),
            externalReference: $externalReference,
        );

        self::getContainer()->get(SubscriptionRepositoryInterface::class)->save($subscription);

        return $subscription;
    }
}
