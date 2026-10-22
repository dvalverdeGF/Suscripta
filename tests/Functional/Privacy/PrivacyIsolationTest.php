<?php

declare(strict_types=1);

namespace App\Tests\Functional\Privacy;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\Membership;
use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Services\Application\CreateService;
use App\Services\Application\Dto\ServiceInput;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Aislamiento entre organizaciones (Fase 16).
 *
 * El multiinquilino no se sostiene con "no mezcles los datos" en el código:
 * se sostiene con una prueba que mete dos organizaciones en la misma base de
 * datos y comprueba que lo que ve una no aparece en la exportación de la otra.
 *
 * La tentación de un filtro de SQL que se Equivoca es escribir
 * `WHERE organization_id = :id` en un sitio y olvidar el `AND deleted_at IS
 * NULL` en otro. Aquí se comprueba lo que el usuario ve, que es lo que
 * importa.
 */
final class PrivacyIsolationTest extends WebTestCase
{
    private KernelBrowser $client;

    private User $ada;

    private Organization $adaOrganization;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $registerUser = self::getContainer()->get(RegisterUser::class);

        $this->ada = $registerUser('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $this->client->loginUser($this->ada);

        $membership = $this->ada->getMemberships()->first();
        self::assertInstanceOf(Membership::class, $membership);
        $this->adaOrganization = $membership->getOrganization();
    }

    public function testTheExportOfAnOrganizationDoesNotContainTheDataOfAnother(): void
    {
        $this->createService($this->adaOrganization, 'Netflix');

        $registerUser = self::getContainer()->get(RegisterUser::class);
        $grace = $registerUser('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');
        $graceMembership = $grace->getMemberships()->first();
        self::assertInstanceOf(Membership::class, $graceMembership);
        $this->createService($graceMembership->getOrganization(), 'Spotify');

        $this->client->request('GET', '/privacy/export.json');

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Netflix', $content);
        self::assertStringNotContainsString('Spotify', $content);
    }

    public function testTheCsvExportOfAnOrganizationDoesNotContainTheDataOfAnother(): void
    {
        $this->createService($this->adaOrganization, 'Netflix');

        $registerUser = self::getContainer()->get(RegisterUser::class);
        $grace = $registerUser('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');
        $graceMembership = $grace->getMemberships()->first();
        self::assertInstanceOf(Membership::class, $graceMembership);
        $this->createService($graceMembership->getOrganization(), 'Spotify');

        $this->client->request('GET', '/privacy/export/services.csv');

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Netflix', $content);
        self::assertStringNotContainsString('Spotify', $content);
    }

    public function testDeletingTheDataOfOneOrganizationDoesNotTouchAnother(): void
    {
        $this->createService($this->adaOrganization, 'Netflix');

        $registerUser = self::getContainer()->get(RegisterUser::class);
        $grace = $registerUser('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');
        $graceMembership = $grace->getMemberships()->first();
        self::assertInstanceOf(Membership::class, $graceMembership);
        $graceOrganization = $graceMembership->getOrganization();
        $this->createService($graceOrganization, 'Spotify');

        $this->client->request('POST', '/privacy/delete', [
            '_token' => $this->deleteToken(),
            'confirmation' => $this->adaOrganization->getName(),
        ]);

        self::assertResponseRedirects('/organization/new');

        self::assertCount(0, $this->servicesOf($this->adaOrganization));
        self::assertCount(1, $this->servicesOf($graceOrganization));
    }

    public function testAMemberOfAnotherOrganizationDoesNotSeeItsData(): void
    {
        $this->createService($this->adaOrganization, 'Netflix');

        $registerUser = self::getContainer()->get(RegisterUser::class);
        $grace = $registerUser('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');

        // Grace entra en la organización de Ada como miembro: es un dato
        // compartido, no una fuga. Lo que no puede es ver la exportación de
        // una organización en la que no está.
        $organizations = self::getContainer()->get(OrganizationRepositoryInterface::class);
        new Membership($grace, $this->adaOrganization, \App\Identity\Domain\Enum\OrganizationRole::MEMBER);
        $organizations->save($this->adaOrganization);

        $this->client->loginUser($grace);
        $this->switchTo($this->adaOrganization);

        $this->client->request('GET', '/privacy/export.json');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Netflix', (string) $this->client->getResponse()->getContent());
    }

    private function deleteToken(): string
    {
        $crawler = $this->client->request('GET', '/privacy');

        $token = $crawler
            ->filter('form[action="/privacy/delete"] input[name="_token"]')
            ->attr('value');

        self::assertIsString($token);

        return $token;
    }

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

    private function createService(Organization $organization, string $name): void
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
