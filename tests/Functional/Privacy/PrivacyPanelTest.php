<?php

declare(strict_types=1);

namespace App\Tests\Functional\Privacy;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\Membership;
use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Enum\OrganizationRole;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Services\Application\CreateService;
use App\Services\Application\Dto\ServiceInput;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

use const JSON_THROW_ON_ERROR;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El panel de privacidad (Fase 16).
 *
 * Se prueba por HTTP porque lo que hay que garantizar es que el usuario
 * **puede** llevarse sus datos y que el borrado **no** ocurre por accidente:
 * hace falta CSRF, hace falta escribir el nombre de la organización y hace
 * falta ser propietario o administrador.
 */
final class PrivacyPanelTest extends WebTestCase
{
    private KernelBrowser $client;

    private User $user;

    private Organization $organization;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $registerUser = self::getContainer()->get(RegisterUser::class);
        $this->user = $registerUser('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $this->client->loginUser($this->user);

        $membership = $this->user->getMemberships()->first();
        self::assertInstanceOf(Membership::class, $membership);
        $this->organization = $membership->getOrganization();
    }

    public function testThePanelIsReachableAndExplainsTheRetention(): void
    {
        $this->client->request('GET', '/privacy');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Privacidad');
        self::assertSelectorTextContains('body', 'Cuánto tiempo guardamos cada cosa');
        self::assertSelectorTextContains('body', 'Correo indexado');
    }

    public function testTheJsonExportContainsTheSectionsOfTheOrganization(): void
    {
        $this->createService('Netflix');

        $this->client->request('GET', '/privacy/export.json');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json; charset=utf-8');

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertArrayHasKey('sections', $payload);
        self::assertArrayHasKey('services', $payload['sections']);
        self::assertSame('Netflix', $payload['sections']['services']['rows'][0]['name']);
    }

    public function testTheJsonExportDoesNotCarrySecrets(): void
    {
        $this->client->request('GET', '/privacy/export.json');

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('password', $content);
        self::assertStringNotContainsString('credentialsEncrypted', $content);
    }

    public function testTheCsvExportOfASectionIsDownloadable(): void
    {
        $this->createService('Netflix');

        $this->client->request('GET', '/privacy/export/services.csv');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=utf-8');
        self::assertStringContainsString('name', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Netflix', (string) $this->client->getResponse()->getContent());
    }

    public function testTheCsvExportOfAnUnknownSectionIsNotFound(): void
    {
        $this->client->request('GET', '/privacy/export/inventado.csv');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletingWithoutAValidCsrfTokenIsForbidden(): void
    {
        $this->client->request('POST', '/privacy/delete', [
            '_token' => 'inventado',
            'confirmation' => $this->organization->getName(),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeletingWithTheWrongConfirmationDoesNotDeleteAnything(): void
    {
        $this->createService('Netflix');

        $this->client->request('POST', '/privacy/delete', [
            '_token' => $this->deleteToken(),
            'confirmation' => 'otra organización',
        ]);

        self::assertResponseRedirects('/privacy');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'no coincide');

        self::assertCount(1, $this->servicesOf($this->organization));
    }

    public function testDeletingWithTheRightConfirmationRemovesTheData(): void
    {
        $this->createService('Netflix');

        $this->client->request('POST', '/privacy/delete', [
            '_token' => $this->deleteToken(),
            'confirmation' => $this->organization->getName(),
        ]);

        // La borración se lleva la organización entera, así que el usuario se
        // queda sin ninguna: el destino es la pantalla para crear una.
        self::assertResponseRedirects('/organization/new');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Se han borrado');

        self::assertCount(0, $this->servicesOf($this->organization));
    }

    public function testAfterDeletingTheDataTheUserCanStartAgain(): void
    {
        $this->createService('Netflix');

        $this->client->request('POST', '/privacy/delete', [
            '_token' => $this->deleteToken(),
            'confirmation' => $this->organization->getName(),
        ]);

        $this->client->followRedirect();

        self::assertSelectorTextContains('h1', 'Crea tu organización');

        $crawler = $this->client->getCrawler();
        $token = $crawler
            ->filter('form#organization-form input[name="organization_form[_token]"]')
            ->attr('value');

        self::assertIsString($token);

        // Se envía el formulario como lo haría el navegador: con su token y
        // con la página de referencia. El token CSRF de Symfony 8 valida el
        // origen de la petición, así que el POST tiene que venir de la
        // pantalla que pintó el formulario.
        $this->client->submitForm('Crear organización', [
            'organization_form[name]' => 'Suscripta de nuevo',
        ]);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        // La organización nueva es la activa y el backoffice vuelve a funcionar.
        $tenantContext = self::getContainer()->get(TenantContext::class);
        self::assertNotNull($tenantContext->getOrganizationId());
    }

    public function testAMemberThatIsNotAnAdministratorCannotDeleteTheData(): void
    {
        $registerUser = self::getContainer()->get(RegisterUser::class);
        $member = $registerUser('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');

        $organizations = self::getContainer()->get(OrganizationRepositoryInterface::class);

        // Grace entra como miembro de la organización de Ada, no como invitada
        // a verla: el permiso se decide por el rol, no por quien pregunta.
        new Membership($member, $this->organization, OrganizationRole::MEMBER);
        $organizations->save($this->organization);

        $this->client->loginUser($member);
        $this->switchTo($this->organization);

        $this->client->request('GET', '/privacy');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action="/privacy/delete"]');
        self::assertSelectorTextContains('body', 'Solo un');
    }

    /**
     * El token CSRF se lee de la propia página: es lo que haría el navegador.
     */
    private function deleteToken(): string
    {
        $crawler = $this->client->request('GET', '/privacy');

        $token = $crawler
            ->filter('form[action="/privacy/delete"] input[name="_token"]')
            ->attr('value');

        self::assertIsString($token);

        return $token;
    }

    /**
     * Cambia la organización activa por la que se indica.
     */
    private function switchTo(Organization $organization): void
    {
        $crawler = $this->client->request('GET', '/');

        $token = $crawler
            ->filter('form[action="/organizations/switch"] input[name="_token"]')
            ->attr('value');

        self::assertIsString($token);

        $this->client->request('POST', '/organizations/switch', [
            '_token' => $token,
            'organization' => $organization->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects('/');
    }

    private function createService(string $name): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $createService = self::getContainer()->get(CreateService::class);

        $tenantContext->runAs($this->organization->getId(), static function () use ($createService, $name): void {
            $createService(new ServiceInput(
                name: $name,
                currency: Currency::EUR,
                billingPeriod: BillingPeriod::MONTHLY,
                amount: Money::of(990, Currency::EUR),
                startedAt: new DateTimeImmutable('2026-01-01'),
            ));
        });
    }

    /**
     * @return list<object>
     */
    private function servicesOf(Organization $organization): array
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $repository = self::getContainer()->get(ServiceRepositoryInterface::class);

        return $tenantContext->runAs(
            $organization->getId(),
            static fn (): array => $repository->findAllForOrganization($organization->getId()),
        );
    }
}
