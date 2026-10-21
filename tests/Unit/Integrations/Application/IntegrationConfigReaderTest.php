<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integrations\Application;

use App\Integrations\Application\IntegrationConfigReader;
use App\Integrations\Application\IntegrationConfigWriter;
use App\Integrations\Application\IntegrationRegistry;
use App\Integrations\Domain\Entity\Integration;
use App\Mailbox\Infrastructure\Crypto\SodiumCredentialCipher;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Integrations\InMemoryIntegrationRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Leer la configuración guardada es la contrapartida de escribirla.
 *
 * Lo que se comprueba aquí es que el lector **descifra** (el secreto vuelve a
 * ser utilizable), que sabe decir si una integración está lista para usarse
 * (activa **y** con credenciales) y que los ajustes que no se han configurado
 * toman el valor por defecto del catálogo.
 *
 * Ese último punto importa: un umbral sin valor guardado no está "sin
 * definir", tiene el de por defecto, y la aplicación debe usarlo.
 */
#[CoversClass(IntegrationConfigReader::class)]
final class IntegrationConfigReaderTest extends TestCase
{
    private InMemoryIntegrationRepository $repository;
    private IntegrationConfigReader $reader;
    private IntegrationConfigWriter $writer;
    private SodiumCredentialCipher $cipher;

    protected function setUp(): void
    {
        $this->repository = new InMemoryIntegrationRepository();
        $this->cipher = new SodiumCredentialCipher('secreto-de-prueba-para-el-cifrador');

        $tenantContext = new TenantContext();
        $tenantContext->setOrganizationId(Uuid::v7());

        $this->writer = new IntegrationConfigWriter(
            repository: $this->repository,
            credentialCipher: $this->cipher,
            tenantContext: $tenantContext,
            logger: new NullLogger(),
        );

        $this->reader = new IntegrationConfigReader(
            repository: $this->repository,
            registry: new IntegrationRegistry(),
            credentialCipher: $this->cipher,
            logger: new NullLogger(),
        );
    }

    public function testItReadsBackTheSavedCredentials(): void
    {
        $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: null,
            credentials: ['api_key' => 'gsk-supersecreto'],
            settings: [],
            enabled: true,
        );

        self::assertSame(['api_key' => 'gsk-supersecreto'], $this->reader->credentials('groq'));
        self::assertSame('gsk-supersecreto', $this->reader->credential('groq', 'api_key'));
    }

    public function testItReadsASingleCredentialThatDoesNotExist(): void
    {
        self::assertNull($this->reader->credential('groq', 'api_key'));
        self::assertSame([], $this->reader->credentials('groq'));
    }

    public function testAnIntegrationThatDoesNotExistHasNoSettings(): void
    {
        self::assertSame([], $this->reader->settings('no-existe'));
        self::assertNull($this->reader->find('no-existe'));
    }

    public function testAnIntegrationThatCannotBeDecryptedReturnsNoCredentials(): void
    {
        $integration = new Integration(Uuid::v7(), 'groq', 'Groq');
        $integration->setCredentialsEncrypted('v1:basura', null);
        $integration->setEnabled(true);
        $this->repository->add($integration);

        self::assertSame([], $this->reader->credentials('groq'));
    }

    public function testAnIntegrationIsNotUsableWhenItIsNotEnabled(): void
    {
        $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: null,
            credentials: ['api_key' => 'gsk-supersecreto'],
            settings: [],
            enabled: false,
        );

        self::assertFalse($this->reader->isUsable('groq'));
    }

    public function testAnIntegrationIsNotUsableWithoutCredentials(): void
    {
        $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: null,
            credentials: [],
            settings: [],
            enabled: true,
        );

        self::assertFalse($this->reader->isUsable('groq'));
    }

    public function testAnIntegrationIsUsableWhenItIsEnabledAndHasCredentials(): void
    {
        $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: null,
            credentials: ['api_key' => 'gsk-supersecreto'],
            settings: [],
            enabled: true,
        );

        self::assertTrue($this->reader->isUsable('groq'));
    }

    public function testAnIntegrationThatDoesNotNeedCredentialsIsUsableWithoutThem(): void
    {
        // Ollama es un servidor local: no tiene clave, y no por eso deja de servir.
        $this->writer->save(
            key: 'ollama',
            label: 'Ollama',
            baseUrl: 'http://localhost:11434',
            credentials: [],
            settings: ['model' => 'llama3.2'],
            enabled: true,
        );

        self::assertTrue($this->reader->isUsable('ollama', requiresCredentials: false));
    }

    public function testTheSettingsOfTheCatalogAreAppliedWhenNothingIsSaved(): void
    {
        $settings = $this->reader->resolvedSettings('groq');

        self::assertSame('llama-3.3-70b-versatile', $settings['model']);
        self::assertSame('2048', $settings['max_tokens']);
    }

    public function testTheSavedSettingsWinOverTheDefaultOnes(): void
    {
        $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: null,
            credentials: [],
            settings: ['model' => 'gemma2-9b-it'],
            enabled: true,
        );

        $settings = $this->reader->resolvedSettings('groq');

        self::assertSame('gemma2-9b-it', $settings['model']);
        self::assertSame('2048', $settings['max_tokens']);
    }

    public function testTheBaseUrlIsRead(): void
    {
        $this->writer->save(
            key: 'ollama',
            label: 'Ollama',
            baseUrl: 'http://localhost:11434',
            credentials: [],
            settings: [],
            enabled: true,
        );

        self::assertSame('http://localhost:11434', $this->reader->baseUrl('ollama'));
    }

    public function testTheBaseUrlIsNullWhenThereIsNone(): void
    {
        $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: '',
            credentials: [],
            settings: [],
            enabled: true,
        );

        self::assertNull($this->reader->baseUrl('groq'));
    }

    public function testTheLabelOfTheCatalogIsUsed(): void
    {
        self::assertSame('Groq', $this->reader->labelFor('groq'));
        self::assertSame('OpenAI', $this->reader->labelFor('openai'));
        self::assertSame('lo-que-sea', $this->reader->labelFor('lo-que-sea'));
    }
}
