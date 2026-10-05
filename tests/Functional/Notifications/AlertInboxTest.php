<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertStatus;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Repository\AlertRepositoryInterface;
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
 * La bandeja de avisos es el canal principal del producto. Se prueba por HTTP
 * porque lo que importa es que el usuario pueda **leer el aviso, entender por
 * qué existe y decidir** sin salir de la pantalla.
 */
final class AlertInboxTest extends WebTestCase
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

    public function testTheInboxIsEmptyForANewAccount(): void
    {
        $this->client->request('GET', '/alerts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-main', 'No hay nada que requiera tu atención');
    }

    public function testItListsAnOpenAlertWithItsReason(): void
    {
        $this->alert();

        $this->client->request('GET', '/alerts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-main', 'Cobro próximo: OVH');
        self::assertSelectorTextContains('.app-main', '29,90');
    }

    public function testItShowsTheRelatedService(): void
    {
        $service = $this->service();
        $this->alert($service->getId());

        $this->client->request('GET', '/alerts');

        self::assertSelectorTextContains('.app-main', 'OVH VPS');
    }

    public function testTheDetailPageExplainsTheAlert(): void
    {
        $alert = $this->alert();

        $this->client->request('GET', '/alerts/'.$alert->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-main', 'Cobro próximo: OVH');
        self::assertSelectorTextContains('.app-main', 'Qué ha pasado');
    }

    public function testAcknowledgingAnAlertClosesIt(): void
    {
        $alert = $this->alert();

        $this->client->request('GET', '/alerts/'.$alert->getId()->toRfc4122());
        $this->client->submit($this->client->getCrawler()->selectButton('Marcar como visto')->form());

        self::assertResponseRedirects('/alerts');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-region', 'marcado como visto');

        self::assertSame(AlertStatus::ACKNOWLEDGED, $this->reload($alert)->getStatus());
    }

    public function testDismissingAnAlertClosesIt(): void
    {
        $alert = $this->alert();

        $this->client->request('GET', '/alerts/'.$alert->getId()->toRfc4122());
        $this->client->submit($this->client->getCrawler()->selectButton('Descartar')->form());

        self::assertResponseRedirects('/alerts');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-region', 'descartado');

        self::assertSame(AlertStatus::DISMISSED, $this->reload($alert)->getStatus());
    }

    public function testAClosedAlertIsNotListedAsOpen(): void
    {
        $alert = $this->alert();
        $alert->dismiss($this->user->getId(), new DateTimeImmutable('2026-10-05 09:00:00'));
        $this->alerts()->save($alert);

        $this->client->request('GET', '/alerts');

        self::assertSelectorTextContains('.app-main', 'No hay nada que requiera tu atención');
    }

    public function testItFiltersByStatus(): void
    {
        $alert = $this->alert();
        $alert->dismiss($this->user->getId(), new DateTimeImmutable('2026-10-05 09:00:00'));
        $this->alerts()->save($alert);

        $this->client->request('GET', '/alerts?status=dismissed');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-main', 'Cobro próximo: OVH');
    }

    public function testAnUnknownStatusFallsBackToOpen(): void
    {
        $this->alert();

        $this->client->request('GET', '/alerts?status=inventado');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-main', 'Cobro próximo: OVH');
    }

    private function alerts(): AlertRepositoryInterface
    {
        return self::getContainer()->get(AlertRepositoryInterface::class);
    }

    private function reload(Alert $alert): Alert
    {
        $fresh = $this->alerts()->find($alert->getId());

        self::assertNotNull($fresh);

        return $fresh;
    }

    private function organizationOf(User $user): Uuid
    {
        $organizations = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($user);

        self::assertNotSame([], $organizations);

        return $organizations[0]['organization']->getId();
    }

    private function alert(?Uuid $serviceId = null): Alert
    {
        $alert = new Alert(
            organizationId: $this->organizationId,
            type: AlertType::UPCOMING_CHARGE,
            severity: AlertSeverity::WARNING,
            title: 'Cobro próximo: OVH',
            message: 'OVH te cobrará 29,90 € el 08/10/2026 (en 3 días).',
            dedupKey: 'upcoming_charge|'.($serviceId?->toRfc4122() ?? '-').'|2026-10-08',
            dueAt: new DateTimeImmutable('2026-10-08'),
            serviceId: $serviceId,
            createdAt: new DateTimeImmutable('2026-10-05 09:00:00'),
        );

        $this->alerts()->save($alert);

        return $alert;
    }

    private function service(): Service
    {
        $tenant = self::getContainer()->get(TenantContext::class);

        return $tenant->runAs($this->organizationId, function (): Service {
            $service = new Service(
                organizationId: $this->organizationId,
                name: 'OVH VPS',
                currency: Currency::EUR,
                billingPeriod: BillingPeriod::MONTHLY,
                source: ServiceSource::MANUAL,
            );
            $service->changePrice(Money::of(2990, Currency::EUR), new DateTimeImmutable('2026-01-01'));

            self::getContainer()->get(ServiceRepositoryInterface::class)->save($service);

            return $service;
        });
    }
}
