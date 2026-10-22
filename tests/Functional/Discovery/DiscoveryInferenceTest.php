<?php

declare(strict_types=1);

namespace App\Tests\Functional\Discovery;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Las inferencias se presentan como **sugerencias**, no como hechos (D-59).
 *
 * Lo que se prueba por HTTP es lo que el usuario ve y lo que puede hacer:
 * que una sospecha se distinga de un hecho, que se pueda apartar con un clic,
 * y que aceptarla aplique la acción correcta sin borrar el historial.
 */
final class DiscoveryInferenceTest extends WebTestCase
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

    private function organizationOf(User $user): Uuid
    {
        $organizations = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($user);

        self::assertNotSame([], $organizations);

        return $organizations[0]['organization']->getId();
    }

    private function service(string $name, string $createdAt = '2024-01-01 00:00:00'): Service
    {
        $service = new Service(
            $this->organizationId,
            $name,
            Currency::EUR,
            BillingPeriod::MONTHLY,
            ServiceSource::MANUAL,
        );
        $service->changePrice(Money::of(1299, Currency::EUR), new DateTimeImmutable($createdAt));

        self::getContainer()->get(ServiceRepositoryInterface::class)->save($service);

        return $service;
    }

    /**
     * @param array<string, mixed> $proposedData
     */
    private function inference(
        DiscoveryType $type,
        array $proposedData,
        ?Uuid $matchedServiceId = null,
    ): Discovery {
        $discovery = new Discovery(
            organizationId: $this->organizationId,
            type: $type,
            detectedAt: new DateTimeImmutable('2026-10-04 09:00:00'),
            proposedData: $proposedData,
        );

        $discovery->applyMatch(90, [
            ['signal' => 'Mismo proveedor', 'weight' => 45, 'detail' => null],
            ['signal' => 'Mismo importe', 'weight' => 30, 'detail' => null],
        ], $matchedServiceId);
        $discovery->applyConfidence(90);
        $discovery->markAsInference();

        self::getContainer()->get(DiscoveryRepositoryInterface::class)->save($discovery);

        return $discovery;
    }

    private function reload(Uuid $id): Discovery
    {
        $discovery = self::getContainer()->get(DiscoveryRepositoryInterface::class)->find($id);

        self::assertNotNull($discovery);

        return $discovery;
    }

    public function testTheIndexMarksTheInferencesAsSuggestions(): void
    {
        $this->inference(DiscoveryType::DUPLICATE, ['serviceName' => 'Netflix']);

        $this->client->request('GET', '/discoveries');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Sugerencia');
        self::assertSelectorTextContains('table', 'Netflix');
    }

    public function testTheIndexCanShowOnlyTheSuggestions(): void
    {
        $this->inference(DiscoveryType::DUPLICATE, ['serviceName' => 'Netflix']);

        $fact = new Discovery(
            organizationId: $this->organizationId,
            type: DiscoveryType::NEW_SERVICE,
            detectedAt: new DateTimeImmutable('2026-10-04 09:00:00'),
            proposedData: ['providerName' => 'OVH', 'amountMinor' => 2990, 'currency' => 'EUR'],
        );
        $fact->applyMatch(60, [], null);
        $fact->applyConfidence(85);
        self::getContainer()->get(DiscoveryRepositoryInterface::class)->save($fact);

        $this->client->request('GET', '/discoveries?scope=suggestions');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Netflix');
        self::assertSelectorTextNotContains('table', 'OVH');

        $this->client->request('GET', '/discoveries?scope=facts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'OVH');
        self::assertSelectorTextNotContains('table', 'Netflix');
    }

    public function testTheIndexCanBeFilteredByType(): void
    {
        $this->inference(DiscoveryType::DUPLICATE, ['serviceName' => 'Netflix']);
        $this->inference(DiscoveryType::DORMANT, ['serviceName' => 'Spotify', 'inactiveDays' => 240]);

        $this->client->request('GET', '/discoveries?type=dormant');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Spotify');
        self::assertSelectorTextNotContains('table', 'Netflix');
    }

    public function testTheDetailSaysThatItIsASuggestionAndNotAFact(): void
    {
        $discovery = $this->inference(DiscoveryType::DUPLICATE, ['serviceName' => 'Netflix']);

        $this->client->request('GET', '/discoveries/'.$discovery->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-main', 'Esto es una sugerencia, no un hecho');
        self::assertSelectorTextContains('.bo-main', 'Fuerza de la señal');
        self::assertSelectorTextNotContains('.bo-main', 'Puntuación de emparejamiento');
    }

    public function testTheDetailExplainsWhereTheSuggestionComesFrom(): void
    {
        $discovery = $this->inference(DiscoveryType::DUPLICATE, [
            'serviceName' => 'Netflix',
            'relatedServiceName' => 'Netflix Familiar',
        ]);

        $this->client->request('GET', '/discoveries/'.$discovery->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-main', 'no viene de un correo');
        self::assertSelectorTextContains('.bo-main', 'Netflix Familiar');
    }

    public function testTheDetailOffersTheInferenceDecisionInsteadOfTheServiceForm(): void
    {
        $discovery = $this->inference(DiscoveryType::DORMANT, ['serviceName' => 'Netflix']);

        $this->client->request('GET', '/discoveries/'.$discovery->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#inference-form');
        self::assertSelectorNotExists('form#discovery-form');
        self::assertSelectorTextContains('.bo-main', 'Sí, es eso');
    }

    public function testAcceptingADuplicateCancelsTheNewestServiceAndKeepsTheHistory(): void
    {
        $original = $this->service('Netflix', '2024-01-01 00:00:00');
        $duplicate = $this->service('Netflix Familiar', '2025-06-01 00:00:00');

        $discovery = $this->inference(DiscoveryType::DUPLICATE, [
            'serviceId' => $original->getId()->toRfc4122(),
            'relatedServiceId' => $duplicate->getId()->toRfc4122(),
            'serviceName' => 'Netflix',
            'relatedServiceName' => 'Netflix Familiar',
        ], $original->getId());

        $this->client->request('GET', '/discoveries/'.$discovery->getId()->toRfc4122());
        $this->client->submitForm('Sí, es eso');

        self::assertResponseRedirects('/discoveries');

        $services = self::getContainer()->get(ServiceRepositoryInterface::class);
        $reloadedDuplicate = $services->find($duplicate->getId());

        self::assertNotNull($reloadedDuplicate);
        self::assertSame(ServiceStatus::CANCELLED, $reloadedDuplicate->getStatus());
        self::assertCount(2, $reloadedDuplicate->getEvents(), 'La cancelación y su nota: el historial no se borra.');

        self::assertSame(DiscoveryStatus::CONFIRMED, $this->reload($discovery->getId())->getStatus());
    }

    public function testAcceptingADormantSuspicionCancelsTheService(): void
    {
        $service = $this->service('Netflix', '2024-01-01 00:00:00');

        $discovery = $this->inference(DiscoveryType::DORMANT, [
            'serviceId' => $service->getId()->toRfc4122(),
            'serviceName' => 'Netflix',
            'inactiveDays' => 240,
        ], $service->getId());

        $this->client->request('GET', '/discoveries/'.$discovery->getId()->toRfc4122());
        $this->client->submitForm('Sí, es eso');

        self::assertResponseRedirects('/discoveries');

        $reloaded = self::getContainer()->get(ServiceRepositoryInterface::class)->find($service->getId());

        self::assertNotNull($reloaded);
        self::assertSame(ServiceStatus::CANCELLED, $reloaded->getStatus());
    }

    public function testDismissingASuspicionKeepsTheReason(): void
    {
        $discovery = $this->inference(DiscoveryType::DORMANT, ['serviceName' => 'Netflix']);

        $this->client->request('GET', '/discoveries/'.$discovery->getId()->toRfc4122());
        $this->client->submitForm('Descartar sospecha', ['reason' => 'Lo uso todos los meses']);

        self::assertResponseRedirects('/discoveries');

        $reloaded = $this->reload($discovery->getId());

        self::assertSame(DiscoveryStatus::IGNORED, $reloaded->getStatus());
        self::assertSame('Lo uso todos los meses', $reloaded->getNotes());
    }

    public function testResolvingRequiresAValidCsrfToken(): void
    {
        $service = $this->service('Netflix', '2024-01-01 00:00:00');
        $discovery = $this->inference(DiscoveryType::DORMANT, [
            'serviceId' => $service->getId()->toRfc4122(),
            'serviceName' => 'Netflix',
        ], $service->getId());

        $this->client->request('POST', '/discoveries/'.$discovery->getId()->toRfc4122().'/resolve', ['_token' => 'falso']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(DiscoveryStatus::PENDING, $this->reload($discovery->getId())->getStatus());
    }

    public function testAFactCannotBeResolvedWithTheInferenceRoute(): void
    {
        $fact = new Discovery(
            organizationId: $this->organizationId,
            type: DiscoveryType::NEW_SERVICE,
            detectedAt: new DateTimeImmutable('2026-10-04 09:00:00'),
            proposedData: ['providerName' => 'OVH', 'amountMinor' => 2990, 'currency' => 'EUR'],
        );
        $fact->applyMatch(60, [], null);
        $fact->applyConfidence(85);
        self::getContainer()->get(DiscoveryRepositoryInterface::class)->save($fact);

        // El token se emite durante una petición real, que es cuando la
        // aplicación tiene su sesión viva; así se usa el mismo almacenamiento
        // que usará la validación del POST siguiente.
        $this->client->disableReboot();
        $token = null;
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            KernelEvents::REQUEST,
            static function (RequestEvent $event) use (&$token, $fact): void {
                if (!$event->isMainRequest()) {
                    return;
                }

                $token = self::getContainer()->get(CsrfTokenManagerInterface::class)
                    ->getToken('discovery_resolve_'.$fact->getId()->toRfc4122())
                    ->getValue();
            },
        );

        $this->client->request('GET', '/discoveries');

        self::assertNotNull($token);

        $this->client->request('POST', '/discoveries/'.$fact->getId()->toRfc4122().'/resolve', ['_token' => $token]);

        self::assertResponseRedirects('/discoveries/'.$fact->getId()->toRfc4122());
        self::assertSame(DiscoveryStatus::PENDING, $this->reload($fact->getId())->getStatus());
    }
}
