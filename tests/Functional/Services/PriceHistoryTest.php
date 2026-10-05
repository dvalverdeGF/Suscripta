<?php

declare(strict_types=1);

namespace App\Tests\Functional\Services;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El historial de precios es lo que hace creíble la promesa del producto: si el
 * usuario no puede ver cuánto pagaba antes y cuánto paga ahora, «controla tus
 * gastos recurrentes» es solo una frase.
 */
final class PriceHistoryTest extends WebTestCase
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

    public function testTheServicePageShowsThePriceHistoryWithItsVariations(): void
    {
        $service = $this->createService('OVH VPS', '10,00');
        $this->changePrice($service, '12,00', '2026-04-01', 'Subida anual');
        $service = $this->reload($service);
        $this->changePrice($service, '15,00', '2026-07-01', 'Cambio de plan');

        $this->client->request('GET', '/services/'.$service->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-main', 'Historial de precios');
        self::assertSelectorTextContains('.app-main', 'Subida anual');
        self::assertSelectorTextContains('.app-main', 'Cambio de plan');

        // +2,00 € (+20 %) y luego +3,00 € (+25 %), no +5,00 € sobre la primera.
        self::assertSelectorTextContains('.app-main', '+2,00');
        self::assertSelectorTextContains('.app-main', '+20,0');
        self::assertSelectorTextContains('.app-main', '+3,00');
        self::assertSelectorTextContains('.app-main', '+25,0');
    }

    public function testTheServicePageSummarisesTheTotalVariation(): void
    {
        $service = $this->createService('OVH VPS', '10,00');
        $this->changePrice($service, '15,00', '2026-07-01');

        $this->client->request('GET', '/services/'.$service->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-main', 'Cuánto ha cambiado desde el principio');
        self::assertSelectorTextContains('.app-main', '+5,00');
        self::assertSelectorTextContains('.app-main', '+50,0');
    }

    public function testAServiceWithASinglePriceDoesNotClaimAnyVariation(): void
    {
        $service = $this->createService('OVH VPS', '10,00');

        $this->client->request('GET', '/services/'.$service->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('.app-main', 'Cuánto ha cambiado desde el principio');
        self::assertSelectorTextContains('.app-main', 'Vigente');
    }

    public function testTheDashboardShowsTheSpendEvolutionOfTheLastMonths(): void
    {
        $service = $this->createService('OVH VPS', '10,00');
        $this->changePrice($service, '15,00', '2026-08-01');

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-main', 'Evolución del gasto');
        // El mes en curso usa el precio vigente, no el de hace seis meses.
        self::assertSelectorTextContains('.app-main', '15,00');
        self::assertSelectorTextContains('.app-main', '10,00');
    }

    public function testAPriceCannotEnterIntoForceBeforeTheCurrentOne(): void
    {
        $service = $this->createService('OVH VPS', '10,00');

        $this->client->request('GET', '/services/'.$service->getId()->toRfc4122());
        $this->client->submitForm('Cambiar precio', [
            'amount' => '8,00',
            'validFrom' => '2025-01-01',
            'note' => '',
        ]);

        self::assertResponseRedirects();
        $this->client->followRedirect();

        // El mensaje tiene que decir qué hacer, no describir la invariante.
        self::assertSelectorTextContains('.alert--danger', 'ajusta primero la fecha de alta del servicio');
        self::assertSelectorTextContains('.app-main', '10,00');
    }

    private function createService(string $name, string $amount): Service
    {
        $this->client->request('GET', '/services/new');
        $this->client->submitForm('Añadir servicio', [
            'service_form[name]' => $name,
            'service_form[amount]' => $amount,
            'service_form[currency]' => 'EUR',
            'service_form[billingPeriod]' => BillingPeriod::MONTHLY->value,
            'service_form[billingIntervalCount]' => '1',
            'service_form[startedAt]' => '2026-01-15',
            'service_form[autoRenews]' => '1',
        ]);

        self::assertResponseRedirects();

        return $this->findServiceByName($name);
    }

    private function changePrice(Service $service, string $amount, string $validFrom, ?string $note = null): void
    {
        $this->client->request('GET', '/services/'.$service->getId()->toRfc4122());
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Cambiar precio', [
            'amount' => $amount,
            'validFrom' => $validFrom,
            'note' => $note ?? '',
        ]);

        self::assertResponseRedirects();
    }

    private function findServiceByName(string $name): Service
    {
        foreach ($this->repository()->findForOrganization(ServiceFilters::none()) as $service) {
            if ($service->getName() === $name) {
                return $service;
            }
        }

        self::fail(sprintf('No se ha creado el servicio «%s».', $name));
    }

    private function reload(Service $service): Service
    {
        $reloaded = $this->repository()->find($service->getId());

        self::assertInstanceOf(Service::class, $reloaded);

        return $reloaded;
    }

    private function repository(): ServiceRepositoryInterface
    {
        return self::getContainer()->get(ServiceRepositoryInterface::class);
    }
}
