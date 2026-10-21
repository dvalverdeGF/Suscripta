<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integrations\Application;

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
 * Guardar una credencial es la operación más delicada del panel.
 *
 * Tres reglas lo gobernan, y las tres se comprueban aquí:
 *
 * 1. **El secreto no se guarda en claro.** Lo que llega a la base de datos es
 *    texto cifrado, y el texto plano no aparece en ninguna parte del estado
 *    guardado.
 * 2. **Dejar el campo en blanco no borra el secreto.** Significa «no lo he
 *    tocado». Borrarlo es una acción explícita.
 * 3. **Un guardado parcial no pierde lo que no se ha tocado.** Si la
 *    integración tiene dos credenciales y el usuario solo escribe una, la otra
 *    se conserva.
 */
#[CoversClass(IntegrationConfigWriter::class)]
final class IntegrationConfigWriterTest extends TestCase
{
    private InMemoryIntegrationRepository $repository;
    private IntegrationConfigWriter $writer;
    private TenantContext $tenantContext;
    private SodiumCredentialCipher $cipher;

    protected function setUp(): void
    {
        $this->repository = new InMemoryIntegrationRepository();
        $this->cipher = new SodiumCredentialCipher('secreto-de-prueba-para-el-cifrador');
        $this->tenantContext = new TenantContext();
        $this->tenantContext->setOrganizationId(Uuid::v7());

        $this->writer = new IntegrationConfigWriter(
            repository: $this->repository,
            credentialCipher: $this->cipher,
            tenantContext: $this->tenantContext,
            logger: new NullLogger(),
        );
    }

    public function testItCreatesTheIntegrationWhenItDoesNotExist(): void
    {
        $integration = $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: 'https://api.groq.com',
            credentials: ['api_key' => 'gsk-supersecreto'],
            settings: ['model' => 'llama-3.3-70b-versatile'],
            enabled: true,
        );

        self::assertSame('groq', $integration->getKey());
        self::assertSame('Groq', $integration->getLabel());
        self::assertTrue($integration->isEnabled());
        self::assertSame('https://api.groq.com', $integration->getBaseUrl());
        self::assertSame(['model' => 'llama-3.3-70b-versatile'], $integration->getSettings());
        self::assertNotNull($this->repository->find('groq'));
    }

    public function testTheSecretIsNeverStoredInClear(): void
    {
        $integration = $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: null,
            credentials: ['api_key' => 'gsk-supersecreto'],
            settings: [],
            enabled: true,
        );

        $stored = $integration->getCredentialsEncrypted();

        self::assertNotNull($stored);
        self::assertStringNotContainsString('gsk-supersecreto', $stored);
        self::assertStringStartsWith('v1:', $stored);
    }

    public function testTheSecretCanBeReadBack(): void
    {
        $integration = $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: null,
            credentials: ['api_key' => 'gsk-supersecreto'],
            settings: [],
            enabled: true,
        );

        $decrypted = $this->cipher->decrypt((string) $integration->getCredentialsEncrypted());

        self::assertSame('gsk-supersecreto', json_decode($decrypted, true)['api_key']);
    }

    public function testAnEmptySecretKeepsTheOneAlreadySaved(): void
    {
        $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: null,
            credentials: ['api_key' => 'gsk-supersecreto'],
            settings: [],
            enabled: true,
        );

        // Segunda visita al formulario: el campo del secreto se pinta vacío.
        $integration = $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: null,
            credentials: ['api_key' => ''],
            settings: ['model' => 'gemma2-9b-it'],
            enabled: true,
        );

        $decrypted = $this->cipher->decrypt((string) $integration->getCredentialsEncrypted());

        self::assertSame('gsk-supersecreto', json_decode($decrypted, true)['api_key']);
        self::assertSame(['model' => 'gemma2-9b-it'], $integration->getSettings());
    }

    public function testANullSecretAlsoKeepsTheOneAlreadySaved(): void
    {
        $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: null,
            credentials: ['api_key' => 'gsk-supersecreto'],
            settings: [],
            enabled: true,
        );

        $integration = $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: null,
            credentials: ['api_key' => null],
            settings: [],
            enabled: true,
        );

        $decrypted = $this->cipher->decrypt((string) $integration->getCredentialsEncrypted());

        self::assertSame('gsk-supersecreto', json_decode($decrypted, true)['api_key']);
    }

    public function testASavedIntegrationWithNoSecretKeepsNoSecret(): void
    {
        $integration = $this->writer->save(
            key: 'ollama',
            label: 'Ollama',
            baseUrl: 'http://localhost:11434',
            credentials: [],
            settings: ['model' => 'llama3.2'],
            enabled: true,
        );

        self::assertFalse($integration->hasCredentials());
        self::assertNull($integration->getCredentialsEncrypted());
    }

    public function testAPartialSecretIsMergedWithTheOneAlreadySaved(): void
    {
        $this->writer->save(
            key: 'openai',
            label: 'OpenAI',
            baseUrl: null,
            credentials: ['api_key' => 'sk-antigua', 'organization' => 'org-123'],
            settings: [],
            enabled: true,
        );

        // El usuario solo cambia la clave; la organización no se toca.
        $integration = $this->writer->save(
            key: 'openai',
            label: 'OpenAI',
            baseUrl: null,
            credentials: ['api_key' => 'sk-nueva', 'organization' => ''],
            settings: [],
            enabled: true,
        );

        $decrypted = json_decode($this->cipher->decrypt((string) $integration->getCredentialsEncrypted()), true);

        self::assertSame('sk-nueva', $decrypted['api_key']);
        self::assertSame('org-123', $decrypted['organization']);
    }

    public function testTheCredentialsAreTrimmed(): void
    {
        $integration = $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: null,
            credentials: ['api_key' => '  gsk-con-espacios  '],
            settings: [],
            enabled: true,
        );

        $decrypted = json_decode($this->cipher->decrypt((string) $integration->getCredentialsEncrypted()), true);

        self::assertSame('gsk-con-espacios', $decrypted['api_key']);
    }

    public function testClearingTheCredentialsRemovesThem(): void
    {
        $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: null,
            credentials: ['api_key' => 'gsk-supersecreto'],
            settings: [],
            enabled: true,
        );

        $this->writer->clearCredentials('groq');

        $integration = $this->repository->find('groq');

        self::assertInstanceOf(Integration::class, $integration);
        self::assertFalse($integration->hasCredentials());
        self::assertNull($integration->getCredentialsEncrypted());
    }

    public function testClearingTheCredentialsOfAnUnknownIntegrationDoesNothing(): void
    {
        $this->writer->clearCredentials('no-existe');

        self::assertNull($this->repository->find('no-existe'));
    }

    public function testASecretThatCannotBeReadIsReplacedInsteadOfFailing(): void
    {
        // Simula una rotación de clave: lo guardado ya no se puede descifrar.
        $integration = new Integration($this->tenantContext->requireOrganizationId(), 'groq', 'Groq');
        $integration->setCredentialsEncrypted('v1:datos-corruptos', null);
        $this->repository->add($integration);

        $saved = $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: null,
            credentials: ['api_key' => 'gsk-nueva'],
            settings: [],
            enabled: true,
        );

        $decrypted = json_decode($this->cipher->decrypt((string) $saved->getCredentialsEncrypted()), true);

        self::assertSame(['api_key' => 'gsk-nueva'], $decrypted);
    }

    public function testTheIntegrationBelongsToTheActiveOrganization(): void
    {
        $integration = $this->writer->save(
            key: 'groq',
            label: 'Groq',
            baseUrl: null,
            credentials: [],
            settings: [],
            enabled: true,
        );

        self::assertTrue($integration->getOrganizationId()->equals($this->tenantContext->requireOrganizationId()));
    }

    public function testTheRegistryKnowsTheProvidersOfTheCatalog(): void
    {
        $registry = new IntegrationRegistry();

        self::assertTrue($registry->knows('groq'));
        self::assertTrue($registry->knows('openai'));
        self::assertTrue($registry->knows('anthropic'));
        self::assertTrue($registry->knows('ollama'));
        self::assertFalse($registry->knows('inventada'));
        self::assertCount(4, $registry->all());
    }
}
