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
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Bandeja de revisión: el punto donde el pipeline deja de ser una promesa y se
 * convierte en una decisión del usuario.
 *
 * Se prueba a través de HTTP real porque lo que importa no es que el caso de
 * uso funcione, sino que el usuario pueda **leer por qué** se le propone algo y
 * que confirmar produzca exactamente el servicio que ha visto en pantalla.
 */
final class DiscoveryReviewTest extends WebTestCase
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

    /**
     * @param array<string, mixed> $proposedData
     */
    private function discovery(
        array $proposedData = [],
        DiscoveryType $type = DiscoveryType::NEW_SERVICE,
        ?Uuid $matchedServiceId = null,
        ?Uuid $sourceMessageId = null,
    ): Discovery {
        $discovery = new Discovery(
            organizationId: $this->organizationId,
            type: $type,
            detectedAt: new DateTimeImmutable('2026-10-04 09:00:00'),
            proposedData: $proposedData + [
                'providerName' => 'OVH',
                'serviceName' => 'OVH',
                'amountMinor' => 2990,
                'currency' => 'EUR',
                'billingPeriod' => 'monthly',
                'documentType' => 'invoice',
            ],
        );

        $discovery->applyMatch(60, [
            ['signal' => 'Dominio del remitente', 'weight' => 20, 'detail' => 'ovh.com'],
            ['signal' => 'Nombre del servicio', 'weight' => 20, 'detail' => 'OVH'],
        ], $matchedServiceId);
        $discovery->applyConfidence(85);

        if (null !== $sourceMessageId) {
            $discovery->attachSourceMessage($sourceMessageId);
        }

        self::getContainer()->get(DiscoveryRepositoryInterface::class)->save($discovery);

        return $discovery;
    }

    private function sourceMessage(): EmailMessage
    {
        $message = new EmailMessage($this->organizationId, Uuid::v7(), 'INBOX', 42);
        $message->applyMetadata(
            messageId: '<factura@ovh.com>',
            fromAddress: 'facturacion@ovh.com',
            fromName: 'OVH',
            replyTo: null,
            senderDomain: 'ovh.com',
            toAddresses: ['ada@example.com'],
            subject: 'Tu factura de octubre',
            receivedAt: new DateTimeImmutable('2026-10-03 08:00:00'),
            sizeBytes: 2048,
            contentType: 'multipart/mixed',
            attachmentNames: ['factura.pdf'],
            attachmentTypes: ['application/pdf'],
        );
        $message->setBodyExcerpt('Factura nº FRA-1. Total 29,90 €.');

        self::getContainer()->get(EmailMessageRepositoryInterface::class)->save($message);

        return $message;
    }

    private function reload(Uuid $id): Discovery
    {
        $discovery = self::getContainer()->get(DiscoveryRepositoryInterface::class)->find($id);

        self::assertNotNull($discovery);

        return $discovery;
    }

    public function testTheIndexShowsThePendingProposals(): void
    {
        $this->discovery();

        $this->client->request('GET', '/discoveries');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Propuestas');
        self::assertSelectorTextContains('table', 'OVH');
        self::assertSelectorTextContains('table', '29,90');
    }

    public function testTheIndexIsEmptyWhenThereIsNothingToReview(): void
    {
        $this->client->request('GET', '/discoveries');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state__title', 'No hay nada pendiente de revisar');
    }

    public function testTheIndexCanBeFilteredByStatus(): void
    {
        $discovery = $this->discovery();
        $discovery->ignore($this->user->getId(), new DateTimeImmutable('2026-10-04 10:00:00'), 'No es mío');
        self::getContainer()->get(DiscoveryRepositoryInterface::class)->save($discovery);

        $this->client->request('GET', '/discoveries?status=ignored');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'OVH');

        $this->client->request('GET', '/discoveries?status=pending');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state__title', 'No hay nada pendiente de revisar');
    }

    public function testTheDetailExplainsWhyTheProposalWasMade(): void
    {
        $message = $this->sourceMessage();
        $discovery = $this->discovery(sourceMessageId: $message->getId());

        $this->client->request('GET', '/discoveries/'.$discovery->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'OVH');
        self::assertSelectorTextContains('.app-main', 'Dominio del remitente');
        self::assertSelectorTextContains('.app-main', 'Puntuación de emparejamiento');
        self::assertSelectorTextContains('.app-main', 'Tu factura de octubre');
        self::assertSelectorTextContains('.app-main', 'Factura nº FRA-1');
        self::assertSelectorTextContains('.app-main', 'Sin IA');
    }

    public function testConfirmingCreatesAServiceMarkedAsDiscovered(): void
    {
        $discovery = $this->discovery();

        $this->client->request('GET', '/discoveries/'.$discovery->getId()->toRfc4122());
        $this->client->submitForm('Confirmar y añadir a mis servicios', [
            'discovery_form[name]' => 'OVH VPS',
            'discovery_form[amount]' => '29,90',
            'discovery_form[currency]' => 'EUR',
            'discovery_form[billingPeriod]' => BillingPeriod::MONTHLY->value,
            'discovery_form[billingIntervalCount]' => '1',
            'discovery_form[autoRenews]' => '1',
        ]);

        self::assertResponseRedirects();

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'OVH VPS');

        $service = self::getContainer()->get(ServiceRepositoryInterface::class)
            ->findForOrganization(ServiceFilters::none())[0] ?? null;

        self::assertNotNull($service);
        self::assertSame('OVH VPS', $service->getName());
        self::assertSame(2990, $service->getCurrentAmount()?->amountMinor);
        self::assertSame(ServiceSource::EMAIL_DISCOVERY, $service->getSource());

        $reloaded = $this->reload($discovery->getId());

        self::assertSame(DiscoveryStatus::EDITED, $reloaded->getStatus(), 'El usuario ha renombrado el servicio: la propuesta no era exacta.');
        self::assertSame($service->getId()->toRfc4122(), $reloaded->getResultingServiceId()?->toRfc4122());
    }

    public function testConfirmingWithoutEditingKeepsTheProposalIntact(): void
    {
        $discovery = $this->discovery();

        $this->client->request('GET', '/discoveries/'.$discovery->getId()->toRfc4122());
        $this->client->submitForm('Confirmar y añadir a mis servicios', [
            'discovery_form[name]' => 'OVH',
            'discovery_form[amount]' => '29,90',
            'discovery_form[currency]' => 'EUR',
            'discovery_form[billingPeriod]' => BillingPeriod::MONTHLY->value,
            'discovery_form[billingIntervalCount]' => '1',
        ]);

        self::assertResponseRedirects();

        self::assertSame(DiscoveryStatus::CONFIRMED, $this->reload($discovery->getId())->getStatus());
    }

    public function testConfirmingAProposalWithAMatchedServiceUpdatesThePriceWithoutLosingHistory(): void
    {
        $services = self::getContainer()->get(ServiceRepositoryInterface::class);
        $service = new Service($this->organizationId, 'OVH', Currency::EUR, BillingPeriod::MONTHLY);
        $service->changePrice(Money::of(1990, Currency::EUR), new DateTimeImmutable('2026-01-01'));
        $services->save($service);

        $discovery = $this->discovery(
            proposedData: ['amountMinor' => 2990],
            type: DiscoveryType::PRICE_CHANGE,
            matchedServiceId: $service->getId(),
        );

        $this->client->request('GET', '/discoveries/'.$discovery->getId()->toRfc4122());
        self::assertSelectorTextContains('.app-main', 'ya tienes registrado');

        $this->client->submitForm('Confirmar y añadir a mis servicios', [
            'discovery_form[name]' => 'OVH',
            'discovery_form[amount]' => '29,90',
            'discovery_form[currency]' => 'EUR',
            'discovery_form[billingPeriod]' => BillingPeriod::MONTHLY->value,
            'discovery_form[billingIntervalCount]' => '1',
        ]);

        self::assertResponseRedirects();

        $reloaded = $services->find($service->getId());

        self::assertNotNull($reloaded);
        self::assertSame(2990, $reloaded->getCurrentAmount()?->amountMinor);
        self::assertCount(2, $reloaded->getPrices(), 'El precio anterior se conserva: el historial no se muta.');
    }

    public function testConfirmingTwiceIsRejected(): void
    {
        $discovery = $this->discovery();
        $discovery->confirm($this->user->getId(), new DateTimeImmutable('2026-10-04 10:00:00'), Uuid::v7());
        self::getContainer()->get(DiscoveryRepositoryInterface::class)->save($discovery);

        $this->client->request('GET', '/discoveries/'.$discovery->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('button:contains("Confirmar y añadir a mis servicios")');
        self::assertSelectorTextContains('.alert--info', 'ya está');
    }

    public function testDismissingKeepsTheProposalWithItsReason(): void
    {
        $discovery = $this->discovery();

        $this->client->request('GET', '/discoveries/'.$discovery->getId()->toRfc4122());
        $this->client->submitForm('Descartar propuesta', ['reason' => 'Ya no lo uso']);

        self::assertResponseRedirects('/discoveries');

        $reloaded = $this->reload($discovery->getId());

        self::assertSame(DiscoveryStatus::IGNORED, $reloaded->getStatus());
        self::assertSame('Ya no lo uso', $reloaded->getNotes());
    }

    public function testDismissingRequiresAValidCsrfToken(): void
    {
        $discovery = $this->discovery();

        $this->client->request('POST', '/discoveries/'.$discovery->getId()->toRfc4122().'/dismiss', ['_token' => 'falso']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(DiscoveryStatus::PENDING, $this->reload($discovery->getId())->getStatus());
    }

    public function testAProposalFromAnotherOrganizationIsNotVisible(): void
    {
        $other = self::getContainer()->get(RegisterUser::class)('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');

        $foreign = new Discovery(
            organizationId: $this->organizationOf($other),
            type: DiscoveryType::NEW_SERVICE,
            detectedAt: new DateTimeImmutable('2026-10-04 09:00:00'),
            proposedData: ['providerName' => 'Secreto', 'amountMinor' => 100, 'currency' => 'EUR'],
        );
        self::getContainer()->get(DiscoveryRepositoryInterface::class)->save($foreign);

        $this->client->request('GET', '/discoveries');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Secreto');

        $this->client->request('GET', '/discoveries/'.$foreign->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
    }
}
