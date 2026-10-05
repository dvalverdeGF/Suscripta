<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\Membership;
use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Enum\OrganizationRole;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Services\Application\CreateService;
use App\Services\Application\Dto\ServiceInput;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El selector de organización activa.
 *
 * Es la prueba de que el aislamiento por organización (DECISIONS.md D-11) no se
 * puede burlar cambiando el identificador que viaja en el formulario.
 */
final class OrganizationSwitchTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');
    }

    public function testTheSwitcherIsHiddenWhenTheUserHasASingleOrganization(): void
    {
        $this->login('ada@example.com');

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#active-organization');
    }

    public function testTheSwitcherListsEveryOrganizationOfTheUser(): void
    {
        $user = $this->login('ada@example.com');
        $this->addOrganization($user, 'Suscripta Labs');

        $this->client->request('GET', '/');

        self::assertSelectorExists('#active-organization');
        self::assertSelectorTextContains('#active-organization', 'Suscripta Labs');

        // La organización de la sesión es la que aparece seleccionada, no la
        // primera de la lista: si no, el selector mentiría.
        self::assertSelectorExists('#active-organization option[selected]');
        self::assertSelectorTextContains('#active-organization option[selected]', 'Ada Lovelace');
    }

    public function testSwitchingChangesTheActiveOrganizationAndTheVisibleData(): void
    {
        $user = $this->login('ada@example.com');
        $second = $this->addOrganization($user, 'Suscripta Labs');

        $this->createServiceIn($second, 'Servicio de la segunda organización');

        $this->client->request('GET', '/services');
        self::assertSelectorTextContains('.empty-state__title', 'Todavía no hay servicios');

        $this->client->request('POST', '/organizations/switch', [
            '_token' => $this->switchToken(),
            'organization' => $second->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects('/');

        $this->client->request('GET', '/services');
        self::assertSelectorTextContains('body', 'Servicio de la segunda organización');

        // Y la organización activa del contexto es la nueva, no solo la vista.
        $tenantContext = self::getContainer()->get(TenantContext::class);
        self::assertSame($second->getId()->toRfc4122(), $tenantContext->getOrganizationId()?->toRfc4122());
    }

    public function testSwitchingToAnOrganizationTheUserDoesNotBelongToIsRejected(): void
    {
        $user = $this->login('ada@example.com');
        $this->addOrganization($user, 'Suscripta Labs');

        $registerUser = self::getContainer()->get(RegisterUser::class);
        $stranger = $registerUser('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');
        $foreign = $stranger->getMemberships()->first();
        self::assertInstanceOf(Membership::class, $foreign);

        $this->client->request('POST', '/organizations/switch', [
            '_token' => $this->switchToken(),
            'organization' => $foreign->getOrganization()->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'No perteneces a esa organización');

        $tenantContext = self::getContainer()->get(TenantContext::class);
        self::assertNotSame(
            $foreign->getOrganization()->getId()->toRfc4122(),
            $tenantContext->getOrganizationId()?->toRfc4122(),
        );
    }

    public function testSwitchingWithAMalformedIdentifierIsRejected(): void
    {
        $user = $this->login('ada@example.com');
        $this->addOrganization($user, 'Suscripta Labs');

        $this->client->request('POST', '/organizations/switch', [
            '_token' => $this->switchToken(),
            'organization' => 'no-soy-un-uuid',
        ]);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'no es válida');
    }

    public function testSwitchingWithoutAValidCsrfTokenIsForbidden(): void
    {
        $user = $this->login('ada@example.com');
        $this->addOrganization($user, 'Suscripta Labs');

        $this->client->request('POST', '/organizations/switch', [
            '_token' => 'inventado',
            'organization' => '0199f1c2-0000-7000-8000-000000000000',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    private function login(string $email): User
    {
        $registerUser = self::getContainer()->get(RegisterUser::class);
        $user = $registerUser($email, 'Sup3rSecret!2026', 'Ada Lovelace');

        $this->client->loginUser($user);

        return $user;
    }

    private function addOrganization(User $user, string $name): Organization
    {
        $organizations = self::getContainer()->get(OrganizationRepositoryInterface::class);

        $organization = new Organization($name, mb_strtolower(str_replace(' ', '-', $name)));
        new Membership($user, $organization, OrganizationRole::ADMIN);

        $organizations->save($organization);

        return $organization;
    }

    private function createServiceIn(Organization $organization, string $name): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $createService = self::getContainer()->get(CreateService::class);

        $tenantContext->runAs($organization->getId(), static function () use ($createService, $name): void {
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
     * El token CSRF se lee de la propia página: es lo que haría el navegador.
     */
    private function switchToken(): string
    {
        $crawler = $this->client->request('GET', '/');

        $token = $crawler
            ->filter('form[action="/organizations/switch"] input[name="_token"]')
            ->attr('value');

        self::assertIsString($token);

        return $token;
    }
}
