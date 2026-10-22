<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ai;

use App\Ai\Application\AiPurposeSelector;
use App\Ai\Domain\Enum\AiPurpose;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Integrations\Application\IntegrationConfigWriter;
use App\Integrations\Application\IntegrationRegistry;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Identity\SwitchesPlan;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Uid\Uuid;

/**
 * La pantalla de elección de IA, por HTTP.
 *
 * ## Las tres decisiones que toma esta pantalla
 *
 * 1. **¿Se usa IA?** El interruptor. Desactivado por defecto (D-08): sin él,
 *    ninguna llamada se hace aunque haya claves configuradas.
 * 2. **¿Qué proveedor para cada necesidad?** Una elección por necesidad, no un
 *    proveedor global, porque el cliente que paga la factura es quien decide
 *    con qué se le cobra.
 * 3. **Solo proveedores que existen.** Un `provider` inventado en el POST se
 *    ignora: la lista válida la pone el código, no el formulario.
 */
final class AiSettingsTest extends WebTestCase
{
    use SwitchesPlan;

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

        // La IA solo existe en el plan PRO (Fase 17): sin esto, estas pruebas
        // estarían probando el plan FREE, que es justo lo que no hacen.
        $this->switchPlanOfFirstMembership($this->user);

        $this->client->loginUser($this->user);
    }

    public function testTheScreenListsEveryPurposeAndProvider(): void
    {
        $this->client->request('GET', '/ai');

        self::assertResponseIsSuccessful();

        $registry = self::getContainer()->get(IntegrationRegistry::class);

        foreach (AiPurpose::cases() as $purpose) {
            self::assertSelectorExists('select[name="provider['.$purpose->value.']"]');
        }

        foreach ($registry->aiProviders() as $provider) {
            self::assertSelectorTextContains('.bo-main', $provider->label);
        }
    }

    public function testTheAiIsDisabledByDefault(): void
    {
        $this->client->request('GET', '/ai');

        self::assertResponseIsSuccessful();

        $checked = $this->client->getCrawler()->filter('input[name="ai_enabled"]')->attr('checked');
        self::assertTrue(null === $checked || 'checked' !== $checked);

        self::assertFalse(self::getContainer()->get(AiPurposeSelector::class)->isEnabled());
    }

    public function testTheChoiceIsSaved(): void
    {
        $this->client->request('GET', '/ai');

        $this->client->submit($this->client->getCrawler()->selectButton('Guardar')->form([
            'ai_enabled' => '1',
            'provider['.AiPurpose::EXTRACTION->value.']' => 'groq',
            'provider['.AiPurpose::SUMMARIZATION->value.']' => 'openai',
        ]));

        self::assertResponseRedirects('/ai');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-region', 'configuración de IA');

        $selector = self::getContainer()->get(AiPurposeSelector::class);

        self::assertTrue($selector->isEnabled());
        self::assertSame('groq', $selector->choices()[AiPurpose::EXTRACTION->value]);
        self::assertSame('openai', $selector->choices()[AiPurpose::SUMMARIZATION->value]);
    }

    public function testTheSavedChoiceIsShownWhenTheScreenIsOpenedAgain(): void
    {
        $this->client->request('GET', '/ai');

        $this->client->submit($this->client->getCrawler()->selectButton('Guardar')->form([
            'ai_enabled' => '1',
            'provider['.AiPurpose::EXTRACTION->value.']' => 'anthropic',
        ]));

        $this->client->request('GET', '/ai');

        $selected = $this->client->getCrawler()
            ->filter('select[name="provider['.AiPurpose::EXTRACTION->value.']"] option[selected]')
            ->attr('value');

        self::assertSame('anthropic', $selected);
    }

    public function testAnInventedProviderIsIgnored(): void
    {
        $this->client->request('GET', '/ai');

        $token = $this->client->getCrawler()->filter('form#ai-settings-form input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/ai', [
            '_token' => $token,
            'ai_enabled' => '1',
            'provider['.AiPurpose::EXTRACTION->value.']' => 'proveedor-inventado',
            'provider[necesidad-inventada]' => 'groq',
        ]);

        self::assertResponseRedirects('/ai');

        $choices = self::getContainer()->get(AiPurposeSelector::class)->choices();

        self::assertArrayNotHasKey('necesidad-inventada', $choices);
        self::assertArrayNotHasKey(AiPurpose::EXTRACTION->value, $choices);
    }

    public function testTurningTheAiOffKeepsTheChoice(): void
    {
        $this->client->request('GET', '/ai');

        $this->client->submit($this->client->getCrawler()->selectButton('Guardar')->form([
            'ai_enabled' => '1',
            'provider['.AiPurpose::EXTRACTION->value.']' => 'groq',
        ]));

        // Se apaga sin tocar la elección: quien la guardó la reactivará.
        $this->client->request('GET', '/ai');

        $form = $this->client->getCrawler()->selectButton('Guardar')->form([
            'provider['.AiPurpose::EXTRACTION->value.']' => 'groq',
        ]);

        // Desmarcar el interruptor es lo que apaga la IA. Hay que quitar la
        // marca de verdad: el crawler envía el valor por defecto del control.
        $enabled = $form['ai_enabled'];
        self::assertInstanceOf(ChoiceFormField::class, $enabled);
        $enabled->untick();

        $this->client->submit($form);

        $selector = self::getContainer()->get(AiPurposeSelector::class);

        self::assertFalse($selector->isEnabled());
        self::assertSame('groq', $selector->choices()[AiPurpose::EXTRACTION->value]);
    }

    public function testAnInvalidCsrfTokenIsForbidden(): void
    {
        $this->client->request('POST', '/ai', [
            '_token' => 'inventado',
            'ai_enabled' => '1',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAProviderWithoutCredentialsIsNotUsable(): void
    {
        $this->client->request('GET', '/ai');

        $this->client->submit($this->client->getCrawler()->selectButton('Guardar')->form([
            'ai_enabled' => '1',
            'provider['.AiPurpose::EXTRACTION->value.']' => 'groq',
        ]));

        // Groq está elegido, pero no hay clave: no hay proveedor real.
        self::assertFalse(self::getContainer()->get(AiPurposeSelector::class)->has(AiPurpose::EXTRACTION));
    }

    public function testAProviderWithCredentialsBecomesUsable(): void
    {
        $this->configureProvider('groq', 'gsk-clave');

        $this->client->request('GET', '/ai');

        $this->client->submit($this->client->getCrawler()->selectButton('Guardar')->form([
            'ai_enabled' => '1',
            'provider['.AiPurpose::EXTRACTION->value.']' => 'groq',
        ]));

        self::assertTrue(self::getContainer()->get(AiPurposeSelector::class)->has(AiPurpose::EXTRACTION));
    }

    /**
     * Configura un proveedor con credenciales, como si el operador lo hubiera
     * hecho desde el panel de integraciones.
     */
    private function configureProvider(string $key, string $apiKey): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $writer = self::getContainer()->get(IntegrationConfigWriter::class);
        $registry = self::getContainer()->get(IntegrationRegistry::class);

        $definition = $registry->find($key);
        self::assertNotNull($definition);

        $tenantContext->runAs($this->organizationId, static function () use ($writer, $definition, $apiKey): void {
            $writer->save(
                key: $definition->key,
                label: $definition->label,
                baseUrl: null,
                credentials: ['api_key' => $apiKey],
                settings: [],
                enabled: true,
            );
        });
    }
}
