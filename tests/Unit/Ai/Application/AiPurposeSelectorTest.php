<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Application;

use App\Ai\Application\AiPurposeSelector;
use App\Ai\Domain\Contract\AiProviderException;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Identity\Domain\Entity\Organization;
use App\Integrations\Application\IntegrationConfigReader;
use App\Integrations\Application\IntegrationRegistry;
use App\Integrations\Domain\Entity\Integration;
use App\Shared\Application\OrganizationSettingsReader;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Ai\FakeAiProvider;
use App\Tests\Support\Ai\FakeAiProviderRegistry;
use App\Tests\Support\Identity\InMemoryOrganizationRepository;
use App\Tests\Support\Integrations\InMemoryIntegrationRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * El selector decide **qué IA se usa para cada necesidad**, y su decisión
 * tiene tres capas que se pueden equivocar por separado:
 *
 * 1. ¿Está la IA activada para la organización? (D-08: desactivada por defecto)
 * 2. ¿Qué proveedor se ha elegido para esa necesidad?
 * 3. ¿Ese proveedor está **realmente configurado** (con clave y activo)?
 *
 * La tercera capa es la que evita el error caro: tener el nombre escrito en los
 * ajustes no significa que exista una clave válida con la que llamar.
 */
#[CoversClass(AiPurposeSelector::class)]
final class AiPurposeSelectorTest extends TestCase
{
    private InMemoryOrganizationRepository $organizations;
    private InMemoryIntegrationRepository $integrations;
    private TenantContext $tenantContext;
    private FakeAiProviderRegistry $registry;
    private AiPurposeSelector $selector;

    protected function setUp(): void
    {
        $this->organizations = new InMemoryOrganizationRepository();
        $this->integrations = new InMemoryIntegrationRepository();
        $this->tenantContext = new TenantContext();

        $organization = new Organization('Acme', 'acme');
        $this->organizations->add($organization);
        $this->tenantContext->setOrganizationId($organization->getId());

        $this->registry = new FakeAiProviderRegistry([
            new FakeAiProvider('groq', AiTier::CHEAP, [AiPurpose::EXTRACTION, AiPurpose::SUMMARIZATION]),
            new FakeAiProvider('openai', AiTier::ADVANCED, [AiPurpose::EXTRACTION, AiPurpose::ADVANCED_EXTRACTION]),
        ]);

        $settings = new OrganizationSettingsReader($this->organizations, $this->tenantContext);
        $config = new IntegrationConfigReader(
            $this->integrations,
            new IntegrationRegistry(),
            new class implements \App\Mailbox\Application\CredentialCipherInterface {
                public function encrypt(string $plaintext): string
                {
                    return $plaintext;
                }

                public function decrypt(string $payload): string
                {
                    return $payload;
                }
            },
            new NullLogger(),
        );

        $this->selector = new AiPurposeSelector($config, $this->registry, $settings, new NullLogger());
    }

    public function testTheAiIsDisabledByDefault(): void
    {
        self::assertFalse($this->selector->isEnabled());
        self::assertFalse($this->selector->has(AiPurpose::EXTRACTION));
        self::assertNull($this->selector->resolve(AiPurpose::EXTRACTION));
    }

    public function testTheChoiceIsIgnoredWhileTheAiIsDisabled(): void
    {
        $this->choose(AiPurpose::EXTRACTION, 'groq');

        self::assertFalse($this->selector->has(AiPurpose::EXTRACTION));
    }

    public function testItResolvesTheChosenProviderOnceTheAiIsEnabled(): void
    {
        $this->enableAi();
        $this->choose(AiPurpose::EXTRACTION, 'groq');
        $this->configureIntegration('groq');

        $provider = $this->selector->forPurpose(AiPurpose::EXTRACTION);

        self::assertSame('groq', $provider->key());
    }

    public function testEachPurposeCanUseADifferentProvider(): void
    {
        $this->enableAi();
        $this->choose(AiPurpose::EXTRACTION, 'groq');
        $this->choose(AiPurpose::ADVANCED_EXTRACTION, 'openai');
        $this->configureIntegration('groq');
        $this->configureIntegration('openai');

        self::assertSame('groq', $this->selector->forPurpose(AiPurpose::EXTRACTION)->key());
        self::assertSame('openai', $this->selector->forPurpose(AiPurpose::ADVANCED_EXTRACTION)->key());
    }

    public function testAProviderWithoutCredentialsIsNotUsable(): void
    {
        $this->enableAi();
        $this->choose(AiPurpose::EXTRACTION, 'groq');

        // Integración guardada pero sin clave: no se puede llamar.
        $integration = new Integration($this->tenantContext->requireOrganizationId(), 'groq', 'Groq');
        $integration->setEnabled(true);
        $this->integrations->add($integration);

        self::assertFalse($this->selector->has(AiPurpose::EXTRACTION));
    }

    public function testADisabledIntegrationIsNotUsable(): void
    {
        $this->enableAi();
        $this->choose(AiPurpose::EXTRACTION, 'groq');

        $integration = new Integration($this->tenantContext->requireOrganizationId(), 'groq', 'Groq');
        $integration->setEnabled(false);
        $integration->setCredentialsEncrypted('cifrado', null);
        $this->integrations->add($integration);

        self::assertFalse($this->selector->has(AiPurpose::EXTRACTION));
    }

    public function testALocalProviderDoesNotNeedCredentials(): void
    {
        $this->enableAi();
        $this->choose(AiPurpose::EXTRACTION, 'ollama');

        $integration = new Integration($this->tenantContext->requireOrganizationId(), 'ollama', 'Ollama');
        $integration->setEnabled(true);
        $this->integrations->add($integration);

        // Sin credenciales, pero el registro de IA no lo conoce: sigue sin
        // resolver porque no hay proveedor de ese nombre.
        self::assertNull($this->selector->resolve(AiPurpose::EXTRACTION));
    }

    public function testAProviderUnknownToTheEngineIsNotResolved(): void
    {
        $this->enableAi();
        $this->choose(AiPurpose::EXTRACTION, 'proveedor-inexistente');
        $this->configureIntegration('proveedor-inexistente');

        self::assertNull($this->selector->resolve(AiPurpose::EXTRACTION));
    }

    public function testAskingForAProviderThatDoesNotExistFails(): void
    {
        $this->enableAi();

        $this->expectException(AiProviderException::class);

        $this->selector->forPurpose(AiPurpose::EXTRACTION);
    }

    public function testAskingForTheAdvancedProviderWithoutOneFails(): void
    {
        $this->enableAi();

        $this->expectException(AiProviderException::class);

        $this->selector->advanced();
    }

    public function testTheConfiguredKeysListTheProvidersInUse(): void
    {
        $this->enableAi();
        $this->choose(AiPurpose::EXTRACTION, 'groq');
        $this->choose(AiPurpose::ADVANCED_EXTRACTION, 'openai');
        $this->configureIntegration('groq');
        $this->configureIntegration('openai');

        self::assertSame(['groq', 'openai'], $this->selector->configuredKeys());
    }

    public function testUnknownPurposesAreDiscardedWhenSaving(): void
    {
        $this->selector->saveChoices([
            'extraction' => 'groq',
            'necesidad-que-no-existe' => 'openai',
        ]);

        self::assertSame(['extraction' => 'groq'], $this->selector->choices());
    }

    private function enableAi(): void
    {
        $this->selector->setEnabled(true);
    }

    private function choose(AiPurpose $purpose, string $providerKey): void
    {
        // `saveChoices()` sustituye el conjunto completo —así funciona el
        // formulario, que envía todas las necesidades—, así que para añadir
        // una se parte de lo que ya había.
        $this->selector->saveChoices([
            ...$this->selector->choices(),
            $purpose->value => $providerKey,
        ]);
    }

    private function configureIntegration(string $key): void
    {
        $integration = new Integration($this->tenantContext->requireOrganizationId(), $key, $key);
        $integration->setEnabled(true);
        $integration->setCredentialsEncrypted('cifrado', null);
        $this->integrations->add($integration);
    }
}
