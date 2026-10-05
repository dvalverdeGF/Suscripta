<?php

declare(strict_types=1);

namespace App\Tests\Functional\Services;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Recorrido funcional completo del inventario de servicios.
 *
 * Cubre el alta manual, la edición, el historial de precios, el ciclo de vida y
 * el borrado, siempre a través de las rutas HTTP reales para que el test falle
 * si se rompe el formulario, la validación o el aislamiento por organización.
 */
final class ServiceCrudTest extends WebTestCase
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

    public function testIndexIsReachableAndEmpty(): void
    {
        $this->client->request('GET', '/services');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Servicios');
        self::assertSelectorTextContains('.empty-state__title', 'Todavía no hay servicios');
    }

    public function testCreateServiceFromTheForm(): void
    {
        $this->client->request('GET', '/services/new');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Añadir servicio', [
            'service_form[name]' => 'OVH VPS',
            'service_form[amount]' => '11,99',
            'service_form[currency]' => 'EUR',
            'service_form[billingPeriod]' => BillingPeriod::MONTHLY->value,
            'service_form[billingIntervalCount]' => '1',
            'service_form[startedAt]' => '2026-01-15',
            'service_form[autoRenews]' => '1',
        ]);

        self::assertResponseRedirects();

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'OVH VPS');
        self::assertSelectorTextContains('.stat__value', '11,99');

        $service = $this->findServiceByName('OVH VPS');

        self::assertSame(1199, $service->getCurrentAmount()?->amountMinor);
        self::assertSame(Currency::EUR, $service->getCurrency());
        self::assertSame(BillingPeriod::MONTHLY, $service->getBillingPeriod());
        self::assertSame(ServiceStatus::ACTIVE, $service->getStatus());
    }

    public function testCreateServiceRejectsEmptyName(): void
    {
        $this->client->request('GET', '/services/new');
        $this->client->submitForm('Añadir servicio', [
            'service_form[name]' => '',
            'service_form[amount]' => '10,00',
            'service_form[currency]' => 'EUR',
            'service_form[billingPeriod]' => BillingPeriod::MONTHLY->value,
            'service_form[billingIntervalCount]' => '1',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__error', 'Indica el nombre del servicio.');
    }

    public function testCreateServiceRejectsMalformedAmount(): void
    {
        $this->client->request('GET', '/services/new');
        $this->client->submitForm('Añadir servicio', [
            'service_form[name]' => 'OVH VPS',
            'service_form[amount]' => 'once euros',
            'service_form[currency]' => 'EUR',
            'service_form[billingPeriod]' => BillingPeriod::MONTHLY->value,
            'service_form[billingIntervalCount]' => '1',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__error', 'formato válido');
    }

    public function testEditService(): void
    {
        $service = $this->createService('OVH VPS', '11,99');

        $this->client->request('GET', '/services/'.$service->getId()->toRfc4122().'/edit');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Guardar cambios', [
            'service_form[name]' => 'OVH VPS Essential',
            'service_form[amount]' => '11,99',
            'service_form[currency]' => 'EUR',
            'service_form[billingPeriod]' => BillingPeriod::ANNUAL->value,
            'service_form[billingIntervalCount]' => '1',
            'service_form[autoRenews]' => '1',
        ]);

        self::assertResponseRedirects();

        $reloaded = $this->reload($service);

        self::assertSame('OVH VPS Essential', $reloaded->getName());
        self::assertSame(BillingPeriod::ANNUAL, $reloaded->getBillingPeriod());
    }

    public function testChangePriceKeepsHistory(): void
    {
        $service = $this->createService('OVH VPS', '11,99');
        $id = $service->getId()->toRfc4122();

        $this->client->request('GET', '/services/'.$id);
        $this->client->submitForm('Cambiar precio', [
            'amount' => '14,99',
            'validFrom' => '2026-11-01',
            'note' => 'Subida anual',
        ]);

        self::assertResponseRedirects();

        $reloaded = $this->reload($service);

        self::assertCount(2, $reloaded->getPrices());
        self::assertSame(1499, $reloaded->getCurrentAmount()?->amountMinor);
        $firstPrice = $reloaded->getPrices()->first();
        self::assertNotFalse($firstPrice);
        self::assertSame('2026-11-01', $firstPrice->getValidTo()?->format('Y-m-d'));
    }

    public function testChangePriceRejectsADateBeforeTheCurrentPrice(): void
    {
        $service = $this->createService('OVH VPS', '11,99');
        $id = $service->getId()->toRfc4122();

        $this->client->request('GET', '/services/'.$id);
        $this->client->submitForm('Cambiar precio', [
            'amount' => '14,99',
            'validFrom' => '2020-01-01',
        ]);

        self::assertResponseRedirects();

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'ajusta primero la fecha de alta del servicio');

        self::assertCount(1, $this->reload($service)->getPrices());
    }

    public function testChangePriceToTheSameAmountDoesNotAddHistory(): void
    {
        $service = $this->createService('OVH VPS', '11,99');
        $id = $service->getId()->toRfc4122();

        $this->client->request('GET', '/services/'.$id);
        $this->client->submitForm('Cambiar precio', [
            'amount' => '11,99',
            'validFrom' => '2026-06-01',
        ]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--info', 'El importe es el mismo');

        self::assertCount(1, $this->reload($service)->getPrices());
    }

    public function testPauseAndResume(): void
    {
        $service = $this->createService('OVH VPS', '11,99');
        $id = $service->getId()->toRfc4122();

        $this->client->request('GET', '/services/'.$id);
        $this->client->submitForm('Pausar');

        self::assertSame(ServiceStatus::PAUSED, $this->reload($service)->getStatus());

        $this->client->request('GET', '/services/'.$id);
        $this->client->submitForm('Reactivar');

        self::assertSame(ServiceStatus::ACTIVE, $this->reload($service)->getStatus());
    }

    public function testCancelKeepsTheServiceAndItsHistory(): void
    {
        $service = $this->createService('OVH VPS', '11,99');
        $id = $service->getId()->toRfc4122();

        $this->client->request('GET', '/services/'.$id);
        $this->client->submitForm('Marcar como cancelado');

        $reloaded = $this->reload($service);

        self::assertSame(ServiceStatus::CANCELLED, $reloaded->getStatus());
        self::assertCount(1, $reloaded->getPrices());
    }

    public function testDeleteRemovesTheService(): void
    {
        $service = $this->createService('OVH VPS', '11,99');
        $id = $service->getId()->toRfc4122();

        $this->client->request('GET', '/services/'.$id);
        $this->client->submitForm('Eliminar definitivamente');

        self::assertResponseRedirects('/services');

        self::assertNull($this->repository()->find($service->getId()));
    }

    public function testIndexFiltersByStatus(): void
    {
        $this->createService('OVH VPS', '11,99');
        $paused = $this->createService('Adobe', '24,19');
        $paused->pause();
        $this->repository()->save($paused);

        $this->client->request('GET', '/services?status=paused');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Adobe');
        self::assertSelectorTextNotContains('table', 'OVH VPS');
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
            'service_form[autoRenews]' => '1',
        ]);

        self::assertResponseRedirects();

        return $this->findServiceByName($name);
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
