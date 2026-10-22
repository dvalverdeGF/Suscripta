<?php

declare(strict_types=1);

namespace App\Tests\Functional\Discovery;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Entity\DiscoveryEvidence;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Revisión por lotes de la bandeja de propuestas.
 *
 * Confirmar veinte propuestas una a una es la forma más rápida de que el
 * usuario deje de usar la bandeja. El lote existe para eso, así que lo que se
 * prueba aquí es el compromiso: **el lote hace exactamente lo que el pipeline
 * propuso, sin tocar los datos**, y una propuesta que falla no tira abajo las
 * demás.
 */
final class DiscoveryBatchTest extends WebTestCase
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

    public function testTheInboxOffersTheBatchToolbarForPendingProposals(): void
    {
        $this->discovery('OVH');
        $this->discovery('Spotify');

        $this->client->request('GET', '/discoveries');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/discoveries/batch"]');
        self::assertSelectorExists('input[name="discoveries[]"]');
        self::assertSelectorExists('button[value="confirm"]');
        self::assertSelectorExists('button[value="dismiss"]');
    }

    public function testTheInboxHasNoBatchToolbarForResolvedProposals(): void
    {
        $discovery = $this->discovery('OVH');
        $discovery->ignore($this->user->getId(), new DateTimeImmutable('2026-10-04 10:00:00'), 'No es mío');
        self::getContainer()->get(DiscoveryRepositoryInterface::class)->save($discovery);

        $this->client->request('GET', '/discoveries?status=ignored');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('button[value="confirm"]');
    }

    public function testConfirmingTheBatchCreatesOneServicePerProposal(): void
    {
        $first = $this->discovery('OVH');
        $second = $this->discovery('Spotify');

        $this->client->request('POST', '/discoveries/batch', [
            '_token' => $this->batchToken(),
            'action' => 'confirm',
            'discoveries' => [
                $first->getId()->toRfc4122(),
                $second->getId()->toRfc4122(),
            ],
        ]);

        self::assertResponseRedirects('/discoveries');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-success', '2 propuesta(s) confirmada(s)');

        $services = self::getContainer()->get(ServiceRepositoryInterface::class)->findForOrganization(ServiceFilters::none());

        self::assertCount(2, $services);

        foreach ($services as $service) {
            self::assertSame(ServiceSource::EMAIL_DISCOVERY, $service->getSource());
        }
    }

    public function testTheBatchLeavesUnselectedProposalsPending(): void
    {
        $selected = $this->discovery('OVH');
        $untouched = $this->discovery('Spotify');

        $this->client->request('POST', '/discoveries/batch', [
            '_token' => $this->batchToken(),
            'action' => 'confirm',
            'discoveries' => [$selected->getId()->toRfc4122()],
        ]);

        self::assertResponseRedirects('/discoveries');

        self::assertSame(DiscoveryStatus::CONFIRMED, $this->reload($selected->getId())->getStatus());
        self::assertSame(DiscoveryStatus::PENDING, $this->reload($untouched->getId())->getStatus());
    }

    public function testTheBatchConfirmsExactlyWhatWasProposed(): void
    {
        $discovery = $this->discovery('OVH');

        $this->client->request('POST', '/discoveries/batch', [
            '_token' => $this->batchToken(),
            'action' => 'confirm',
            'discoveries' => [$discovery->getId()->toRfc4122()],
        ]);

        $service = self::getContainer()->get(ServiceRepositoryInterface::class)->findForOrganization(ServiceFilters::none())[0] ?? null;

        self::assertNotNull($service);
        self::assertSame('OVH', $service->getName());
        self::assertSame(2990, $service->getCurrentAmount()?->amountMinor);
        self::assertSame(BillingPeriod::MONTHLY, $service->getBillingPeriod());
        // Sin tocar los datos, la propuesta no se considera «editada».
        self::assertSame(DiscoveryStatus::CONFIRMED, $this->reload($discovery->getId())->getStatus());
    }

    public function testTheBatchCanDiscardTheSelectedProposals(): void
    {
        $first = $this->discovery('OVH');
        $second = $this->discovery('Spotify');

        $this->client->request('POST', '/discoveries/batch', [
            '_token' => $this->batchToken(),
            'action' => 'dismiss',
            'discoveries' => [
                $first->getId()->toRfc4122(),
                $second->getId()->toRfc4122(),
            ],
        ]);

        self::assertResponseRedirects('/discoveries');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-info', '2 propuesta(s) descartada(s)');

        self::assertSame(DiscoveryStatus::IGNORED, $this->reload($first->getId())->getStatus());
        self::assertSame(DiscoveryStatus::IGNORED, $this->reload($second->getId())->getStatus());
    }

    public function testAFailingProposalDoesNotAbortTheBatch(): void
    {
        $alreadyConfirmed = $this->discovery('OVH');
        $alreadyConfirmed->confirm($this->user->getId(), new DateTimeImmutable('2026-10-04 10:00:00'), Uuid::v7());
        self::getContainer()->get(DiscoveryRepositoryInterface::class)->save($alreadyConfirmed);

        $pending = $this->discovery('Spotify');

        $this->client->request('POST', '/discoveries/batch', [
            '_token' => $this->batchToken(),
            'action' => 'confirm',
            'discoveries' => [
                $alreadyConfirmed->getId()->toRfc4122(),
                $pending->getId()->toRfc4122(),
            ],
        ]);

        self::assertResponseRedirects('/discoveries');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', '1 propuesta(s) confirmada(s)');
        self::assertSelectorTextContains('.alert-success', '1 no se han podido confirmar');

        // La segunda propuesta sí se procesó: el lote no se abortó.
        self::assertSame(DiscoveryStatus::CONFIRMED, $this->reload($pending->getId())->getStatus());
    }

    public function testTheBatchRejectsAnInvalidCsrfToken(): void
    {
        $discovery = $this->discovery('OVH');

        $this->client->request('POST', '/discoveries/batch', [
            '_token' => 'falso',
            'action' => 'confirm',
            'discoveries' => [$discovery->getId()->toRfc4122()],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(DiscoveryStatus::PENDING, $this->reload($discovery->getId())->getStatus());
    }

    public function testTheBatchIgnoresMalformedIdentifiers(): void
    {
        // Hay una propuesta viva para que la bandeja renderice el formulario
        // de lote, pero el lote sólo recibe identificadores imposibles.
        $this->discovery('OVH');

        $this->client->request('POST', '/discoveries/batch', [
            '_token' => $this->batchToken(),
            'action' => 'confirm',
            'discoveries' => ['no-es-un-uuid', '../../etc/passwd'],
        ]);

        self::assertResponseRedirects('/discoveries');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-info', 'No has seleccionado ninguna propuesta');
    }

    public function testTheBatchRejectsAnUnknownAction(): void
    {
        $discovery = $this->discovery('OVH');

        $this->client->request('POST', '/discoveries/batch', [
            '_token' => $this->batchToken(),
            'action' => 'destruir',
            'discoveries' => [$discovery->getId()->toRfc4122()],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheDetailCountsTheEmailsSupportingTheProposal(): void
    {
        $discovery = $this->discovery('OVH');
        $other = $this->discovery('OVH');

        $repository = self::getContainer()->get(DiscoveryRepositoryInterface::class);
        $repository->saveEvidence(new DiscoveryEvidence(
            $discovery->getId(),
            new DateTimeImmutable('2026-10-04 09:00:00'),
            Uuid::v7(),
        ));
        $repository->saveEvidence(new DiscoveryEvidence(
            $discovery->getId(),
            new DateTimeImmutable('2026-10-04 09:10:00'),
            Uuid::v7(),
        ));
        $repository->saveEvidence(new DiscoveryEvidence(
            $other->getId(),
            new DateTimeImmutable('2026-10-04 09:20:00'),
            Uuid::v7(),
        ));

        $this->client->request('GET', '/discoveries/'.$discovery->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-main', '2 correos distintos sostienen esta propuesta');
    }

    public function testNoServiceIsCreatedWithoutAnExplicitDecision(): void
    {
        $this->discovery('OVH');
        $this->discovery('Spotify');

        $this->client->request('GET', '/discoveries');

        self::assertResponseIsSuccessful();

        // Mirar la bandeja no crea nada: la decisión es explícita.
        self::assertCount(0, self::getContainer()->get(ServiceRepositoryInterface::class)->findForOrganization(ServiceFilters::none()));
    }

    /**
     * Token CSRF del formulario de lote, leído de la propia página.
     *
     * Se extrae del HTML en lugar de pedirlo al gestor de tokens porque el
     * gestor guarda el token en la sesión, que todavía no existe si se pide
     * antes de hacer la primera petición.
     *
     * El token se busca **dentro** del formulario de lote y no en el primer
     * `_token` de la página: el backoffice incluye otros formularios (el de
     * reenviar la verificación de correo, por ejemplo) y sus tokens no valen
     * para esta acción.
     */
    private function batchToken(): string
    {
        $this->client->request('GET', '/discoveries');

        $token = $this->client->getCrawler()
            ->filter('form[action="/discoveries/batch"] input[name="_token"]')
            ->attr('value');

        self::assertIsString($token, 'No se ha encontrado el token CSRF del lote.');

        return $token;
    }

    private function organizationOf(User $user): Uuid
    {
        $organizations = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($user);

        self::assertNotSame([], $organizations);

        return $organizations[0]['organization']->getId();
    }

    private function discovery(string $providerName): Discovery
    {
        $discovery = new Discovery(
            organizationId: $this->organizationId,
            type: DiscoveryType::NEW_SERVICE,
            detectedAt: new DateTimeImmutable('2026-10-04 09:00:00'),
            proposedData: [
                'providerName' => $providerName,
                'serviceName' => $providerName,
                'amountMinor' => 2990,
                'currency' => 'EUR',
                'billingPeriod' => 'monthly',
            ],
        );

        $discovery->applyMatch(60, [
            ['signal' => 'Dominio del remitente', 'weight' => 20, 'detail' => 'ovh.com'],
        ]);
        $discovery->applyConfidence(85);

        self::getContainer()->get(DiscoveryRepositoryInterface::class)->save($discovery);

        return $discovery;
    }

    private function reload(Uuid $id): Discovery
    {
        $discovery = self::getContainer()->get(DiscoveryRepositoryInterface::class)->find($id);

        self::assertNotNull($discovery);

        return $discovery;
    }
}
