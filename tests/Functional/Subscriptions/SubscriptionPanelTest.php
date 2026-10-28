<?php

declare(strict_types=1);

namespace App\Tests\Functional\Subscriptions;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Subscriptions\Domain\Entity\Subscription;
use App\Subscriptions\Domain\Enum\SubscriptionStatus;
use App\Subscriptions\Domain\Repository\SubscriptionFilters;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use DateTimeImmutable;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Recorrido funcional del panel de suscripciones.
 *
 * Todo pasa por las rutas HTTP reales: si se rompe el formulario, el token CSRF
 * o el aislamiento por organización, el test falla.
 */
final class SubscriptionPanelTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private Uuid $organizationId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $this->organizationId = $this->organizationOf($this->user);
        $this->client->loginUser($this->user);
    }

    public function testIndexIsReachableAndEmpty(): void
    {
        $this->client->request('GET', '/subscriptions');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Suscripciones');
        self::assertSelectorTextContains('.bo-empty__title', 'Todavía no hay suscripciones');
    }

    public function testCreateSubscriptionFromTheForm(): void
    {
        $this->client->request('GET', '/subscriptions/new');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Añadir suscripción', [
            'subscription_form[name]' => 'Plan Pro',
            'subscription_form[currency]' => Currency::EUR->value,
            'subscription_form[billingPeriod]' => BillingPeriod::MONTHLY->value,
            'subscription_form[billingIntervalCount]' => '1',
            'subscription_form[startedAt]' => '2026-01-15',
            'subscription_form[externalReference]' => 'sub_1Nabc',
        ]);

        self::assertResponseRedirects();

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Plan Pro');
        self::assertSelectorTextContains('.bo-metric__value', '15/01/2026');

        $subscription = $this->findByName('Plan Pro');

        self::assertSame(SubscriptionStatus::ACTIVE, $subscription->getStatus());
        self::assertSame(Currency::EUR, $subscription->getCurrency());
        self::assertSame(BillingPeriod::MONTHLY, $subscription->getBillingPeriod());
        self::assertSame('2026-02-15', $subscription->getCurrentPeriodEnd()->format('Y-m-d'));
        self::assertSame('sub_1Nabc', $subscription->getExternalReference());
    }

    public function testCreateSubscriptionRejectsAnEmptyName(): void
    {
        $this->client->request('GET', '/subscriptions/new');
        $this->client->submitForm('Añadir suscripción', [
            'subscription_form[name]' => '',
            'subscription_form[currency]' => Currency::EUR->value,
            'subscription_form[billingPeriod]' => BillingPeriod::MONTHLY->value,
            'subscription_form[billingIntervalCount]' => '1',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'Indica a qué corresponde la suscripción.');
    }

    public function testASubscriptionCanBeLinkedToAService(): void
    {
        $service = $this->createService('OVH VPS');

        $this->client->request('GET', '/subscriptions/new');
        $this->client->submitForm('Añadir suscripción', [
            'subscription_form[name]' => 'Plan Pro',
            'subscription_form[currency]' => Currency::EUR->value,
            'subscription_form[billingPeriod]' => BillingPeriod::MONTHLY->value,
            'subscription_form[billingIntervalCount]' => '1',
            'subscription_form[startedAt]' => '2026-01-15',
            'subscription_form[serviceId]' => $service->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects();

        $subscription = $this->findByName('Plan Pro');

        self::assertSame($service->getId()->toRfc4122(), $subscription->getServiceId()?->toRfc4122());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.bo-page-header__subtitle', 'OVH VPS');
    }

    public function testEditSubscription(): void
    {
        $subscription = $this->createSubscription('Plan Pro', '2026-01-15');

        $this->client->request('GET', '/subscriptions/'.$subscription->getId()->toRfc4122().'/edit');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Guardar cambios', [
            'subscription_form[name]' => 'Plan Pro anual',
            'subscription_form[currency]' => Currency::EUR->value,
            'subscription_form[billingPeriod]' => BillingPeriod::ANNUAL->value,
            'subscription_form[billingIntervalCount]' => '1',
            'subscription_form[startedAt]' => '2026-01-15',
        ]);

        self::assertResponseRedirects();

        $reloaded = $this->reload($subscription);

        self::assertSame('Plan Pro anual', $reloaded->getName());
        self::assertSame(BillingPeriod::ANNUAL, $reloaded->getBillingPeriod());
        self::assertSame('2027-01-15', $reloaded->getCurrentPeriodEnd()->format('Y-m-d'));
    }

    public function testPauseAndResume(): void
    {
        $subscription = $this->createSubscription('Plan Pro', '2026-01-15');
        $id = $subscription->getId()->toRfc4122();

        $this->client->request('GET', '/subscriptions/'.$id);
        $this->client->submitForm('Pausar');

        self::assertSame(SubscriptionStatus::PAUSED, $this->reload($subscription)->getStatus());

        $this->client->request('GET', '/subscriptions/'.$id);
        $this->client->submitForm('Reactivar');

        self::assertSame(SubscriptionStatus::ACTIVE, $this->reload($subscription)->getStatus());
    }

    public function testRenewMovesThePeriodForward(): void
    {
        $subscription = $this->createSubscription('Plan Pro', '2026-01-15');
        $id = $subscription->getId()->toRfc4122();

        $this->client->request('GET', '/subscriptions/'.$id);
        $this->client->submitForm('Renovar ahora');

        self::assertResponseRedirects();

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Renovada hasta el');

        // El periodo nuevo nunca empieza antes de hoy: el de enero ya venció.
        $reloaded = $this->reload($subscription);
        self::assertSame(
            new DateTimeImmutable('today')->format('Y-m-d'),
            $reloaded->getCurrentPeriodStart()->format('Y-m-d'),
        );
        self::assertSame(
            new DateTimeImmutable('today')->modify('+1 month')->format('Y-m-d'),
            $reloaded->getCurrentPeriodEnd()->format('Y-m-d'),
        );
    }

    public function testCancelKeepsThePaidPeriod(): void
    {
        $subscription = $this->createSubscription('Plan Pro', '2026-01-15');
        $id = $subscription->getId()->toRfc4122();

        $this->client->request('GET', '/subscriptions/'.$id);
        $this->client->submitForm('No renovar');

        self::assertResponseRedirects();

        $reloaded = $this->reload($subscription);

        self::assertSame(SubscriptionStatus::CANCELED, $reloaded->getStatus());
        self::assertSame('2026-02-15', $reloaded->getEndsAt()?->format('Y-m-d'));

        $this->client->followRedirect();
        self::assertAnySelectorTextContains('.alert-warning', 'No se renovará');
    }

    public function testDeleteRemovesTheSubscription(): void
    {
        $subscription = $this->createSubscription('Plan Pro', '2026-01-15');
        $id = $subscription->getId()->toRfc4122();

        $this->client->request('GET', '/subscriptions/'.$id);
        $this->client->submitForm('Eliminar definitivamente');

        self::assertResponseRedirects('/subscriptions');
        self::assertNull($this->repository()->find($subscription->getId()));
    }

    public function testIndexFiltersByStatus(): void
    {
        $this->createSubscription('Plan Pro', '2026-01-15');
        $paused = $this->createSubscription('Soporte prioritario', '2026-02-01');
        $paused->pause();
        $this->repository()->save($paused);

        $this->client->request('GET', '/subscriptions?status=paused');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Soporte prioritario');
        self::assertSelectorTextNotContains('table', 'Plan Pro');
    }

    /**
     * El criterio de la fase: servicios y suscripciones son dos listas distintas.
     */
    public function testSubscriptionsAndServicesAreTwoSeparateLists(): void
    {
        $this->createService('OVH VPS');
        $this->createSubscription('Plan Pro', '2026-01-15');

        $this->client->request('GET', '/services');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'OVH VPS');
        self::assertSelectorTextNotContains('table', 'Plan Pro');

        $this->client->request('GET', '/subscriptions');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Plan Pro');
        self::assertSelectorTextNotContains('table', 'OVH VPS');
    }

    private function createSubscription(string $name, string $startedAt): Subscription
    {
        $this->client->request('GET', '/subscriptions/new');
        $this->client->submitForm('Añadir suscripción', [
            'subscription_form[name]' => $name,
            'subscription_form[currency]' => Currency::EUR->value,
            'subscription_form[billingPeriod]' => BillingPeriod::MONTHLY->value,
            'subscription_form[billingIntervalCount]' => '1',
            'subscription_form[startedAt]' => $startedAt,
        ]);

        self::assertResponseRedirects();

        return $this->findByName($name);
    }

    private function createService(string $name): Service
    {
        $service = new Service(
            organizationId: $this->organizationId,
            name: $name,
            currency: Currency::EUR,
            billingPeriod: BillingPeriod::MONTHLY,
            status: ServiceStatus::ACTIVE,
        );

        $services = self::getContainer()->get(ServiceRepositoryInterface::class);
        $services->save($service);

        return $service;
    }

    private function organizationOf(User $user): Uuid
    {
        $organizations = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($user);

        self::assertNotSame([], $organizations);

        return $organizations[0]['organization']->getId();
    }

    private function findByName(string $name): Subscription
    {
        foreach ($this->repository()->findForOrganization(new SubscriptionFilters()) as $subscription) {
            if ($subscription->getName() === $name) {
                return $subscription;
            }
        }

        self::fail(sprintf('No se ha creado la suscripción «%s».', $name));
    }

    private function reload(Subscription $subscription): Subscription
    {
        $reloaded = $this->repository()->find($subscription->getId());

        self::assertInstanceOf(Subscription::class, $reloaded);

        return $reloaded;
    }

    private function repository(): SubscriptionRepositoryInterface
    {
        return self::getContainer()->get(SubscriptionRepositoryInterface::class);
    }
}
