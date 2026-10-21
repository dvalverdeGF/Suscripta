<?php

declare(strict_types=1);

namespace App\Tests\Functional\Integrations;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Integrations\Application\IntegrationConfigReader;
use App\Integrations\Application\IntegrationConfigWriter;
use App\Integrations\Application\IntegrationRegistry;
use App\Shared\Application\TenantContext;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * El panel de integraciones, por HTTP.
 *
 * ## Lo que se prueba aquí y por qué
 *
 * Lo que importa de una pantalla de credenciales no es que "funcione", sino
 * tres cosas concretas:
 *
 * 1. **El secreto no vuelve al navegador.** Es la propiedad de seguridad de
 *    toda la pantalla: si el token guardado aparece en el HTML, entonces
 *    aparece en el historial, en la caché del navegador y en cualquier copia de
 *    la página.
 * 2. **Guardar en blanco no borra.** El campo del secreto se pinta vacío, así
 *    que "vacío" es ambiguo. Si "vacío" borrara, cualquier guardado por otro
 *    motivo dejaría la integración muerta.
 * 3. **Un campo inventado no se guarda.** El controlador solo lee los nombres
 *    que la definición declara; un POST manipulado no puede meter ajustes
 *    arbitrarios en la configuración.
 */
final class IntegrationPanelTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private Uuid $organizationId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');

        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($this->user);
        self::assertNotEmpty($memberships);
        $this->organizationId = $memberships[0]['organization']->getId();

        $this->client->loginUser($this->user);
    }

    public function testTheIndexListsEveryKnownIntegration(): void
    {
        $this->client->request('GET', '/integrations');

        self::assertResponseIsSuccessful();

        $registry = self::getContainer()->get(IntegrationRegistry::class);

        foreach ($registry->all() as $definition) {
            self::assertSelectorTextContains('.bo-main', $definition->label);
        }
    }

    public function testTheEditScreenRendersTheFieldsDeclaredByTheDefinition(): void
    {
        $this->client->request('GET', '/integrations/openai');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="credential_api_key"]');
        self::assertSelectorExists('input[name="credential_organization"]');
        self::assertSelectorExists('select[name="setting_model"]');
        self::assertSelectorExists('input[name="setting_max_tokens"]');
    }

    public function testTheSecretIsNeverRenderedInClear(): void
    {
        $this->saveCredentials('openai', ['api_key' => 'sk-secreto-de-prueba']);

        $this->client->request('GET', '/integrations/openai');

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('sk-secreto-de-prueba', $content);

        // El campo se pinta vacío, y su estado se dice aparte.
        $value = $this->client->getCrawler()->filter('input[name="credential_api_key"]')->attr('value');
        self::assertTrue(null === $value || '' === $value, 'El campo del secreto no lleva ningún valor.');
        self::assertSelectorTextContains('.bo-main', 'Ya configurada');
    }

    public function testTheSecretIsNotEvenInTheValueAttributeOfTheForm(): void
    {
        $this->saveCredentials('groq', ['api_key' => 'gsk-secreto']);

        $this->client->request('GET', '/integrations/groq');

        self::assertStringNotContainsString('gsk-secreto', (string) $this->client->getResponse()->getContent());
    }

    public function testSavingTheCredentialsKeepsThemEncrypted(): void
    {
        $this->client->request('GET', '/integrations/openai');

        $this->client->submit($this->client->getCrawler()->selectButton('Guardar')->form([
            'credential_api_key' => 'sk-nueva-clave',
            'credential_organization' => '',
            'setting_model' => 'gpt-4o',
            'setting_max_tokens' => '1024',
            'enabled' => '1',
        ]));

        self::assertResponseRedirects('/integrations/openai');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-region', 'se ha guardado');

        $reader = self::getContainer()->get(IntegrationConfigReader::class);

        self::assertSame('sk-nueva-clave', $reader->credential('openai', 'api_key'));
        self::assertSame('gpt-4o', $reader->settings('openai')['model']);
        self::assertSame('1024', $reader->settings('openai')['max_tokens']);
    }

    public function testTheStoredSecretIsNotReadableAsPlainTextInTheDatabase(): void
    {
        $this->saveCredentials('openai', ['api_key' => 'sk-nueva-clave']);

        $stored = self::getContainer()->get(IntegrationConfigReader::class)->find('openai');

        self::assertNotNull($stored);
        self::assertNotNull($stored->getCredentialsEncrypted());
        self::assertStringNotContainsString('sk-nueva-clave', (string) $stored->getCredentialsEncrypted());
    }

    public function testLeavingTheSecretBlankKeepsTheStoredOne(): void
    {
        $this->saveCredentials('openai', ['api_key' => 'sk-original']);

        $this->client->request('GET', '/integrations/openai');

        // Se reenvía el formulario sin tocar el secreto: cambia el modelo.
        $this->client->submit($this->client->getCrawler()->selectButton('Guardar')->form([
            'setting_model' => 'gpt-4o-mini',
            'enabled' => '1',
        ]));

        self::assertResponseRedirects('/integrations/openai');

        $reader = self::getContainer()->get(IntegrationConfigReader::class);

        self::assertSame('sk-original', $reader->credential('openai', 'api_key'));
    }

    public function testAnInventedFieldIsIgnored(): void
    {
        $this->client->request('GET', '/integrations/openai');

        $token = $this->client->getCrawler()->filter('form#integration-form input[name="_token"]')->attr('value');
        self::assertIsString($token);

        // Se manda a mano porque el crawler solo conoce los campos del
        // formulario: lo que se prueba es precisamente un campo que el
        // formulario no tiene.
        $this->client->request('POST', '/integrations/openai', [
            '_token' => $token,
            'credential_api_key' => 'sk-legitima',
            'setting_model' => 'gpt-4o',
            'setting_max_tokens' => '2048',
            'setting_inventado' => 'valor-inventado',
            'credential_inventado' => 'valor-inventado',
            'enabled' => '1',
        ]);

        self::assertResponseRedirects('/integrations/openai');

        $reader = self::getContainer()->get(IntegrationConfigReader::class);
        $settings = $reader->settings('openai');

        self::assertArrayNotHasKey('inventado', $settings);
        self::assertArrayNotHasKey('setting_inventado', $settings);
        self::assertSame('gpt-4o', $settings['model']);
    }

    public function testTheCredentialsCanBeClearedExplicitly(): void
    {
        $this->saveCredentials('openai', ['api_key' => 'sk-a-borrar']);

        $this->client->request('GET', '/integrations/openai');

        $form = $this->client->getCrawler()->filter('form[action="/integrations/openai/credentials/clear"]')->form();

        $this->client->submit($form);

        self::assertResponseRedirects('/integrations/openai');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-region', 'Se han borrado las credenciales');

        $reader = self::getContainer()->get(IntegrationConfigReader::class);

        self::assertNull($reader->credential('openai', 'api_key'));
        self::assertFalse($reader->isUsable('openai'));
    }

    public function testAnInvalidValueIsRejectedAndNothingIsSaved(): void
    {
        $this->client->request('GET', '/integrations/openai');

        $token = $this->client->getCrawler()->filter('form#integration-form input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/integrations/openai', [
            '_token' => $token,
            'credential_api_key' => 'sk-valida',
            'setting_model' => 'gpt-4o',
            'setting_max_tokens' => '999999',
            'enabled' => '1',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-main', 'No se ha guardado');

        $reader = self::getContainer()->get(IntegrationConfigReader::class);

        self::assertNull($reader->credential('openai', 'api_key'));
    }

    public function testAnUnknownIntegrationIsNotFound(): void
    {
        $this->client->request('GET', '/integrations/inventada');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousVisitorsCannotReachThePanel(): void
    {
        self::ensureKernelShutdown();

        $client = self::createClient();
        $client->request('GET', '/integrations');

        self::assertResponseRedirects();
    }

    /**
     * Guarda credenciales por la aplicación, saltándose el formulario.
     *
     * @param array<string, string> $credentials
     */
    private function saveCredentials(string $key, array $credentials): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $writer = self::getContainer()->get(IntegrationConfigWriter::class);
        $registry = self::getContainer()->get(IntegrationRegistry::class);

        $definition = $registry->find($key);
        self::assertNotNull($definition);

        $tenantContext->runAs($this->organizationId, static function () use ($writer, $definition, $credentials): void {
            $writer->save(
                key: $definition->key,
                label: $definition->label,
                baseUrl: null,
                credentials: $credentials,
                settings: [],
                enabled: true,
            );
        });
    }
}
