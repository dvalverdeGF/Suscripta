<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\Domain\Repository\ApiKeyRepositoryInterface;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Identity\CompletesOnboarding;

use function str_contains;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * El panel de claves de API del backoffice (Fase 18).
 *
 * ## Las tres propiedades que se prueban
 *
 * 1. **El secreto se enseña una vez y no vuelve.** En la base de datos solo
 *    está la huella. Si el secreto reapareciera en el listado, en el historial
 *    o al recargar, dejaría de ser una credencial.
 * 2. **La clave se crea con los ámbitos que se piden.** `write` arrastra
 *    `read`, y eso tiene que verse en el listado.
 * 3. **Revocar corta el acceso.** Es la operación que se usa cuando una clave
 *    se filtra, así que no puede ser decorativa.
 */
final class ApiKeyPanelTest extends WebTestCase
{
    use CompletesOnboarding;

    private KernelBrowser $client;
    private Uuid $organizationId;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $user = self::getContainer()->get(RegisterUser::class)('claves@example.com', 'Sup3rSecret!2026', 'Key Owner');
        $this->completeOnboarding($user);

        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($user);
        self::assertNotEmpty($memberships);
        $this->organizationId = $memberships[0]['organization']->getId();

        $this->client->loginUser($user);
    }

    /**
     * Crea una clave pasando por el formulario, como lo haría una persona.
     *
     * El envío se hace con un POST explícito en vez de `$crawler->form()`
     * porque los dos ámbitos son casillas con el mismo nombre
     * (`api_key_form[scopes][]`) y `DomCrawler` registra solo la última: no
     * hay forma de marcar una y desmarcar la otra desde el objeto `Form`.
     * Se lee el token CSRF de la página y se envía el resto a mano.
     *
     * @param list<string> $scopes
     */
    private function createKey(string $name, array $scopes): void
    {
        $crawler = $this->client->request('GET', '/api-keys/new');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('#api-key-form input[name="api_key_form[_token]"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/api-keys/new', [
            'api_key_form' => [
                'name' => $name,
                'scopes' => $scopes,
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/api-keys/created');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function secretFromCreatedPage(): string
    {
        $secret = $this->client->getCrawler()->filter('#api-key-secret')->attr('value');
        self::assertIsString($secret);

        return $secret;
    }

    public function testTheIndexExplainsWhatAKeyIs(): void
    {
        $this->client->request('GET', '/api-keys');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Claves de API');
    }

    public function testCreatingAKeyShowsTheSecretExactlyOnce(): void
    {
        $this->createKey('Integración con el ERP', ['read']);

        $secret = $this->secretFromCreatedPage();

        self::assertStringStartsWith('sk_', $secret);

        // Al recargar ya no está: la sesión lo borró al leerlo.
        $this->client->request('GET', '/api-keys/created');
        self::assertResponseRedirects('/api-keys');

        $this->client->followRedirect();
        self::assertFalse(str_contains((string) $this->client->getResponse()->getContent(), $secret));
    }

    public function testTheIndexNeverShowsTheSecret(): void
    {
        $this->createKey('Sin fuga', ['read']);
        $secret = $this->secretFromCreatedPage();

        $this->client->request('GET', '/api-keys');
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Sin fuga', $html);
        self::assertFalse(str_contains($html, $secret), 'El listado no puede contener la clave en claro.');
    }

    public function testWriteImpliesReadInThePanel(): void
    {
        $this->createKey('Con escritura', ['write']);

        $tenant = self::getContainer()->get(TenantContext::class);
        $keys = self::getContainer()->get(ApiKeyRepositoryInterface::class)->findForOrganization($this->organizationId);
        $tenant->setOrganizationId(null);

        self::assertCount(1, $keys);

        $scopes = array_map(static fn ($scope): string => $scope->value, $keys[0]->getScopes());

        self::assertContains('read', $scopes);
        self::assertContains('write', $scopes);
    }

    public function testRevokingAKeyMarksItAsRevoked(): void
    {
        $this->createKey('Para revocar', ['read']);

        $tenant = self::getContainer()->get(TenantContext::class);
        $keys = self::getContainer()->get(ApiKeyRepositoryInterface::class)->findForOrganization($this->organizationId);
        $tenant->setOrganizationId(null);

        self::assertCount(1, $keys);
        self::assertFalse($keys[0]->isRevoked());

        $crawler = $this->client->request('GET', '/api-keys');
        // El primer `_token` del documento es el del cierre de sesión: hay que
        // leer el del formulario de la fila, que apunta a la ruta de revocar.
        $token = $crawler
            ->filter('form[action$="/revoke"] input[name="_token"]')
            ->first()
            ->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/api-keys/'.$keys[0]->getId()->toRfc4122().'/revoke', ['_token' => $token]);
        self::assertResponseRedirects('/api-keys');

        $tenant->setOrganizationId(null);
        $reloaded = self::getContainer()->get(ApiKeyRepositoryInterface::class)->find($keys[0]->getId());

        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isRevoked());
    }
}
