<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Catalog\Domain\Entity\Category;
use App\Catalog\Domain\Repository\CategoryRepositoryInterface;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Services\Application\CreateService;
use App\Services\Application\Dto\ServiceInput;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * El panel es la respuesta a la promesa del producto: «conecta tu correo y
 * descubre automáticamente todo lo que estás pagando». Estas pruebas fijan las
 * preguntas que tiene que responder (PRODUCT.md §3) y que el panel no se rompe
 * cuando no hay nada que mostrar.
 */
final class DashboardTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $this->client->loginUser($this->user);
    }

    public function testAnEmptyDashboardInvitesTheUserToConnectAMailbox(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Panel');
        self::assertSelectorTextContains('.empty-state__title', 'Todavía no hay datos que mostrar');
        self::assertSelectorTextContains('.empty-state__text', 'Conecta tu correo de facturación');
    }

    public function testTheDashboardShowsTheMonthlyAndAnnualCost(): void
    {
        $this->createService('OVH VPS', 1199, BillingPeriod::MONTHLY);
        $this->createService('Dominio .com', 1200, BillingPeriod::ANNUAL);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.stat__label', 'Coste mensual equivalente');
        // 11,99 €/mes + 12,00 €/año (= 1,00 €/mes) = 12,99 €/mes
        self::assertSelectorTextContains('.stat__value', '12,99');
        self::assertSelectorTextContains('.stat__hint', '155,88');
    }

    public function testTheDashboardCountsOnlyActiveServices(): void
    {
        $this->createService('OVH VPS', 1199, BillingPeriod::MONTHLY);
        $paused = $this->createService('Servicio en pausa', 5000, BillingPeriod::MONTHLY);
        $paused->pause();
        $this->repository()->save($paused);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.stat__value', '11,99');
        self::assertSelectorTextContains('.app-main', '2 en total');
    }

    public function testTheDashboardListsUpcomingCharges(): void
    {
        $service = $this->createService('OVH VPS', 1199, BillingPeriod::MONTHLY);
        $service->setNextChargeAt(new DateTimeImmutable('2026-10-12'));
        $this->repository()->save($service);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.timeline__label', 'OVH VPS');
        self::assertSelectorTextContains('.timeline__date', '12/10/2026');
    }

    public function testTheDashboardListsUpcomingRenewals(): void
    {
        $service = $this->createService('Dominio .com', 1200, BillingPeriod::ANNUAL);
        $service->setRenewalAt(new DateTimeImmutable('2026-10-20'));
        $this->repository()->save($service);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.timeline__label', 'Dominio .com');
        self::assertSelectorTextContains('.timeline__date', '20/10/2026');
    }

    public function testTheDashboardShowsWhatHasChanged(): void
    {
        $service = $this->createService('OVH VPS', 1199, BillingPeriod::MONTHLY);
        $service->changePrice(Money::of(1499, Currency::EUR), new DateTimeImmutable('2026-10-01'), ServiceSource::MANUAL, 'Subida de tarifa');
        $this->repository()->save($service);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-main', 'Qué ha cambiado');
        self::assertSelectorTextContains('.app-main', '14,99');
    }

    public function testTheDashboardDoesNotSumDifferentCurrencies(): void
    {
        $this->createService('OVH VPS', 1199, BillingPeriod::MONTHLY);
        $this->createService('GitHub', 400, BillingPeriod::MONTHLY, Currency::USD);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.stat__value', '2 monedas');
        self::assertSelectorTextContains('.app-main', 'Coste por moneda');
        self::assertSelectorTextContains('.app-main', 'USD');
    }

    public function testTheDashboardIsReadableOnAMobileViewport(): void
    {
        $this->createService('OVH VPS', 1199, BillingPeriod::MONTHLY);
        $this->createService('GitHub', 400, BillingPeriod::MONTHLY, Currency::USD);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();

        // El criterio de aceptación de ARCHITECTURE.md §12: ninguna tabla puede
        // desbordar en horizontal, así que todas van envueltas y etiquetadas.
        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('table--responsive', $content);
        self::assertStringContainsString('data-label=', $content);
        self::assertStringContainsString('name="viewport"', $content);
    }

    public function testTheDashboardBreaksTheCostDownByCategory(): void
    {
        $hosting = $this->createCategory('Hosting', 'hosting');
        $software = $this->createCategory('Software', 'software');

        $this->createService('OVH VPS', 1199, BillingPeriod::MONTHLY, categoryId: $hosting->getId());
        $this->createService('Hetzner', 500, BillingPeriod::MONTHLY, categoryId: $hosting->getId());
        $this->createService('GitHub', 400, BillingPeriod::MONTHLY, categoryId: $software->getId());

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-main', 'En qué se te va el dinero');
        self::assertSelectorTextContains('.app-main', 'Hosting');
        self::assertSelectorTextContains('.app-main', '16,99');
        self::assertSelectorTextContains('.app-main', 'Software');
    }

    public function testTheDashboardRanksTheMostExpensiveServices(): void
    {
        $this->createService('Dominio .com', 1200, BillingPeriod::ANNUAL);
        $this->createService('OVH VPS', 1199, BillingPeriod::MONTHLY);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-main', 'Los que más cuestan');

        $content = (string) $this->client->getResponse()->getContent();
        self::assertLessThan(
            mb_strpos($content, 'Dominio .com'),
            mb_strpos($content, 'OVH VPS'),
            'El servicio más caro al mes tiene que aparecer antes.',
        );
    }

    public function testTheDashboardCountsServicesByStatus(): void
    {
        $this->createService('OVH VPS', 1199, BillingPeriod::MONTHLY);
        $paused = $this->createService('En pausa', 500, BillingPeriod::MONTHLY);
        $paused->pause();
        $this->repository()->save($paused);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-main', 'Servicios por estado');
        self::assertSelectorTextContains('.app-main', 'Activo: 1');
        self::assertSelectorTextContains('.app-main', 'En pausa: 1');
    }

    public function testTheDashboardTotalsTheChargesOfTheNextThirtyAndSixtyDays(): void
    {
        $soon = $this->createService('Cobro próximo', 1199, BillingPeriod::MONTHLY);
        $soon->setNextChargeAt(new DateTimeImmutable('2026-10-20'));
        $this->repository()->save($soon);

        $later = $this->createService('Cobro lejano', 2000, BillingPeriod::MONTHLY);
        $later->setNextChargeAt(new DateTimeImmutable('2026-11-15'));
        $this->repository()->save($later);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card__footer', 'Total en 30 días');
        self::assertSelectorTextContains('.card__footer', '11,99');
        self::assertSelectorTextContains('.card__footer', '31,99');
    }

    private function createCategory(string $name, string $slug): Category
    {
        $category = new Category($name, $slug, $this->organizationId());
        self::getContainer()->get(CategoryRepositoryInterface::class)->save($category);

        return $category;
    }

    private function createService(
        string $name,
        int $amountMinor,
        BillingPeriod $period,
        Currency $currency = Currency::EUR,
        ?Uuid $categoryId = null,
    ): Service {
        $tenant = self::getContainer()->get(TenantContext::class);
        $createService = self::getContainer()->get(CreateService::class);
        $organizationId = $this->organizationId();

        return $tenant->runAs($organizationId, fn (): Service => $createService(
            new ServiceInput(
                name: $name,
                currency: $currency,
                billingPeriod: $period,
                amount: Money::of($amountMinor, $currency),
                categoryId: $categoryId,
                startedAt: new DateTimeImmutable('2026-01-15'),
            ),
            $this->user->getId(),
        ));
    }

    private function organizationId(): Uuid
    {
        $organizations = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($this->user);

        self::assertNotSame([], $organizations);

        return $organizations[0]['organization']->getId();
    }

    private function repository(): ServiceRepositoryInterface
    {
        return self::getContainer()->get(ServiceRepositoryInterface::class);
    }
}
