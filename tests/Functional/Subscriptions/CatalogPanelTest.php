<?php

declare(strict_types=1);

namespace App\Tests\Functional\Subscriptions;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Identity\Domain\Security\Roles;
use App\Subscriptions\Application\LinkSubscriptionsToPlans;
use App\Subscriptions\Domain\Entity\Plan;
use App\Subscriptions\Domain\Repository\PlanPriceRepositoryInterface;
use App\Subscriptions\Domain\Repository\PlanRepositoryInterface;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Recorrido funcional del catálogo comercial.
 *
 * Todo pasa por las rutas HTTP reales: si el rol no protege la pantalla, si el
 * formulario no guarda o si el precio no cierra al publicar otro, el test falla.
 */
#[Group('catalog')]
final class CatalogPanelTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $operator;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->operator = self::getContainer()->get(RegisterUser::class)('ops@example.com', 'Sup3rSecret!2026', 'Operadora');
        $this->operator->setRoles([Roles::OPERATOR]);
        self::getContainer()->get(UserRepositoryInterface::class)->save($this->operator);

        $this->client->loginUser($this->operator);
    }

    public function testACustomerWithoutTheOperatorRoleCannotSeeTheCatalog(): void
    {
        $customer = self::getContainer()->get(RegisterUser::class)('cliente@example.com', 'Sup3rSecret!2026', 'Cliente');
        $this->client->loginUser($customer);

        $this->client->request('GET', '/catalog');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheOperatorSeesTheEmptyCatalog(): void
    {
        $this->client->request('GET', '/catalog');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Catálogo');
        self::assertSelectorTextContains('.bo-empty__title', 'El catálogo está vacío');
    }

    public function testTheOperatorCreatesAPlanWithAPriceAndAFeature(): void
    {
        $this->client->request('GET', '/catalog/new');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Añadir plan', [
            'plan_form[code]' => 'pro',
            'plan_form[name]' => 'Profesional',
            'plan_form[tagline]' => 'Para quien vive de sus suscripciones',
            'plan_form[description]' => 'Sin techo de servicios.',
            'plan_form[position]' => '1',
            'plan_form[isActive]' => '1',
            'plan_form[isHighlighted]' => '1',
        ]);

        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Profesional');

        $plan = $this->findPlan();
        self::assertTrue($plan->isActive());
        self::assertTrue($plan->isHighlighted());

        // El precio se publica desde la ficha del plan.
        $this->client->clickLink('Añadir precio');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Guardar precio', [
            'plan_price_form[currency]' => 'EUR',
            'plan_price_form[amount]' => '19,00',
            'plan_price_form[billingPeriod]' => 'monthly',
        ]);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.bo-table', '19,00');

        // Y la característica, con el formulario en línea de la propia ficha.
        $this->client->submitForm('Añadir', [
            'plan_feature_form[label]' => 'Servicios sin límite',
            'plan_feature_form[included]' => '1',
        ]);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        // La lista de caracteristicas, no el menu lateral: hay muchos `ul`.
        self::assertSelectorTextContains('ul.list-unstyled', 'Servicios sin límite');

        // Los límites del plan se leen de la configuración, no se editan aquí.
        self::assertSelectorTextContains('.catalog-limits', 'Límites que aplica el producto');
    }

    public function testPublishingANewPriceClosesThePreviousOne(): void
    {
        $this->createPlanThroughThePanel('Profesional', 'pro');
        $planId = $this->findPlan()->getId()->toRfc4122();

        $this->publishPrice($planId, '19,00');
        $this->publishPrice($planId, '29,00');

        $prices = self::getContainer()->get(PlanPriceRepositoryInterface::class);
        $current = $prices->findCurrentForPlan($this->findPlan()->getId());
        $history = $prices->findHistoryForPlan($this->findPlan()->getId());

        self::assertCount(1, $current);
        self::assertSame(2900, $current[0]->getAmountMinor());
        self::assertCount(1, $history);
        self::assertSame(1900, $history[0]->getAmountMinor());
    }

    public function testTheBackfillLinksASubscriptionWithItsPlan(): void
    {
        $this->createPlanThroughThePanel('Profesional', 'pro');

        // Una suscripción escrita a mano antes de que existiera el catálogo.
        $this->client->request('GET', '/subscriptions/new');
        $this->client->submitForm('Añadir suscripción', [
            'subscription_form[name]' => 'profesional',
            'subscription_form[currency]' => 'EUR',
            'subscription_form[billingPeriod]' => 'monthly',
            'subscription_form[billingIntervalCount]' => '1',
        ]);
        self::assertResponseRedirects();

        $subscriptions = self::getContainer()->get(SubscriptionRepositoryInterface::class);
        $pending = $subscriptions->findWithoutPlan();
        self::assertCount(1, $pending);

        $linked = self::getContainer()->get(LinkSubscriptionsToPlans::class)();

        self::assertCount(1, $linked);
        self::assertSame('Profesional', $linked[0]['plan']);
        self::assertSame([], $subscriptions->findWithoutPlan());
    }

    private function publishPrice(string $planId, string $amount): void
    {
        $this->client->request('GET', '/catalog/'.$planId.'/prices/new');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Guardar precio', [
            'plan_price_form[currency]' => 'EUR',
            'plan_price_form[amount]' => $amount,
            'plan_price_form[billingPeriod]' => 'monthly',
        ]);

        self::assertResponseRedirects();
    }

    private function createPlanThroughThePanel(string $name, string $code): void
    {
        $this->client->request('GET', '/catalog/new');
        $this->client->submitForm('Añadir plan', [
            'plan_form[code]' => $code,
            'plan_form[name]' => $name,
            'plan_form[position]' => '0',
            'plan_form[isActive]' => '1',
        ]);
        self::assertResponseRedirects();
    }

    private function findPlan(): Plan
    {
        $plans = self::getContainer()->get(PlanRepositoryInterface::class)->findAll();

        self::assertNotEmpty($plans);

        return $plans[0];
    }
}
