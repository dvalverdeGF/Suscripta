<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\Billing\Domain\Entity\SalesInvoice;
use App\Billing\Domain\Enum\InvoiceStatus;
use App\Billing\Domain\Repository\SalesInvoiceRepositoryInterface;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use App\Subscriptions\Domain\Entity\Subscription;
use App\Subscriptions\Domain\Enum\SubscriptionStatus;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * El panel de facturación, por HTTP (Fase 24).
 *
 * ## Lo que se prueba y por qué
 *
 * Que el cliente vea **lo que le hemos cobrado a él y solo a él**. Es la
 * propiedad que importa: una factura de otra organización en esta pantalla es
 * una fuga de datos entre inquilinos, y el panel es justo el sitio donde nadie
 * mira dos veces porque «son mis facturas».
 */
final class BillingPanelTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private Uuid $organizationId;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');

        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($this->user);
        self::assertNotEmpty($memberships);
        $this->organizationId = $memberships[0]['organization']->getId();
    }

    public function testACustomerWithoutSessionIsSentToTheLogin(): void
    {
        $this->client->request('GET', '/facturacion');

        self::assertResponseRedirects();
    }

    public function testAnOrganizationWithoutInvoicesSeesTheEmptyState(): void
    {
        $this->signIn();

        $this->client->request('GET', '/facturacion');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Facturación');
        self::assertSelectorTextContains('.bo-empty__title', 'Todavía no hay recibos');
    }

    public function testThePanelListsTheInvoicesOfTheOrganizationAndTheTotalPaid(): void
    {
        $this->signIn();

        $this->storeInvoice('in_1', 'SUS-2026-0001', 2900, InvoiceStatus::PAID);
        $this->storeInvoice('in_2', 'SUS-2026-0002', 29000, InvoiceStatus::PAID);

        $this->client->request('GET', '/facturacion');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'SUS-2026-0001');
        self::assertSelectorTextContains('table', 'SUS-2026-0002');

        // El total suma lo cobrado en el año: 29,00 € + 290,00 € = 319,00 €.
        self::assertAnySelectorTextContains('.bo-metric__value', '319,00');
    }

    public function testAnInvoiceOfAnotherOrganizationIsNotShown(): void
    {
        $this->signIn();

        $this->storeInvoice('in_1', 'SUS-2026-0001', 2900, InvoiceStatus::PAID);

        $other = self::getContainer()->get(RegisterUser::class)('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');
        $otherMemberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($other);
        self::assertNotEmpty($otherMemberships);

        self::getContainer()->get(SalesInvoiceRepositoryInterface::class)->save(new SalesInvoice(
            organizationId: $otherMemberships[0]['organization']->getId(),
            externalInvoiceId: 'in_ajena',
            amount: Money::of(9900, Currency::EUR),
            status: InvoiceStatus::PAID,
            issuedAt: new DateTimeImmutable('-1 day'),
            number: 'SUS-2026-9999',
        ));

        $this->client->request('GET', '/facturacion');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'SUS-2026-0001');
        self::assertStringNotContainsString('SUS-2026-9999', (string) $this->client->getResponse()->getContent());
    }

    public function testThePanelShowsTheCurrentPlanWhenThereIsOne(): void
    {
        $this->signIn();

        $subscription = new Subscription(
            organizationId: $this->organizationId,
            name: 'Plan Pro',
            currency: Currency::EUR,
            billingPeriod: BillingPeriod::MONTHLY,
            billingIntervalCount: 1,
            startedAt: new DateTimeImmutable('-1 day'),
            status: SubscriptionStatus::ACTIVE,
            createdByUserId: null,
        );

        // El panel enseña la suscripción que cobra la pasarela; una creada a
        // mano sin referencia externa no es «el plan actual».
        $subscription->syncWithGateway(
            status: SubscriptionStatus::ACTIVE,
            currentPeriodStart: new DateTimeImmutable('-1 day'),
            currentPeriodEnd: new DateTimeImmutable('+29 days'),
            externalReference: 'sub_1',
        );

        self::getContainer()->get(SubscriptionRepositoryInterface::class)->save($subscription);

        $this->client->request('GET', '/facturacion');

        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('.bo-metric__value', 'Plan Pro');
    }

    private function signIn(): void
    {
        $this->client->loginUser($this->user);
    }

    private function storeInvoice(string $externalId, string $number, int $amount, InvoiceStatus $status): void
    {
        self::getContainer()->get(SalesInvoiceRepositoryInterface::class)->save(new SalesInvoice(
            organizationId: $this->organizationId,
            externalInvoiceId: $externalId,
            amount: Money::of($amount, Currency::EUR),
            status: $status,
            issuedAt: new DateTimeImmutable('-1 day'),
            number: $number,
        ));
    }
}
