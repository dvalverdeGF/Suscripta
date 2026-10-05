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

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Control de acceso y aislamiento por organización del inventario de servicios.
 *
 * Vive en su propia clase porque `WebTestCase::createClient()` solo puede
 * llamarse una vez por test: un test que arranca sesión y luego pretende
 * comprobar el caso anónimo reutiliza el mismo cliente autenticado y nunca ve
 * la redirección al login.
 */
final class ServiceAccessTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/services');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testAnonymousUserCannotReachTheCreateForm(): void
    {
        $client = self::createClient();
        $client->request('GET', '/services/new');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * El criterio de verificación de la Fase 1: si se desactiva el filtro
     * `tenant`, este test debe fallar.
     */
    public function testServiceOfAnotherOrganizationIsNotVisible(): void
    {
        $client = self::createClient();
        $client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $container = self::getContainer();
        $register = $container->get(RegisterUser::class);

        $ada = $register('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $client->loginUser($ada);

        $client->request('GET', '/services/new');
        $client->submitForm('Añadir servicio', [
            'service_form[name]' => 'OVH VPS de Ada',
            'service_form[amount]' => '11,99',
            'service_form[currency]' => 'EUR',
            'service_form[billingPeriod]' => BillingPeriod::MONTHLY->value,
            'service_form[billingIntervalCount]' => '1',
            'service_form[autoRenews]' => '1',
        ]);
        self::assertResponseRedirects();

        // Consume the flash message so it does not leak into the next user's page.
        $client->followRedirect();

        $adaService = $this->findServiceByName('OVH VPS de Ada');
        $adaServiceId = $adaService->getId()->toRfc4122();

        // Grace Hopper pertenece a otra organización y no debe ver nada de Ada.
        $grace = $register('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');
        $client->loginUser($grace);

        $client->request('GET', '/services');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state__title', 'Todavía no hay servicios');
        self::assertSelectorTextNotContains('.app-main', 'OVH VPS de Ada');

        $client->request('GET', '/services/'.$adaServiceId);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/services/'.$adaServiceId.'/edit');
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/services/'.$adaServiceId.'/delete', [
            '_token' => 'csrf-token',
        ]);
        self::assertResponseStatusCodeSame(404);

        // El servicio de Ada sigue intacto y accesible para ella.
        $client->loginUser($ada);
        $client->request('GET', '/services/'.$adaServiceId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'OVH VPS de Ada');
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

    private function repository(): ServiceRepositoryInterface
    {
        return self::getContainer()->get(ServiceRepositoryInterface::class);
    }
}
