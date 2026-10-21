<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Application;

use App\Ai\Application\AiPriceCatalog;
use App\Ai\Application\AiUsageRecorder;
use App\Ai\Domain\Contract\AiProviderException;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Ai\Domain\ValueObject\AiResponse;
use App\Integrations\Application\IntegrationConfigReader;
use App\Integrations\Application\IntegrationRegistry;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Ai\FakeAiProvider;
use App\Tests\Support\Ai\InMemoryAiUsageRepository;
use App\Tests\Support\Integrations\InMemoryIntegrationRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * El registro de uso es la fuente de verdad del gasto en IA (D-36).
 *
 * Sus dos propiedades decisivas: se registra **también el fallo** (un fallo
 * consume tokens igual que un acierto) y se calcula el coste **en el momento de
 * la llamada** (el precio de un token cambia, y el histórico no puede).
 */
#[CoversClass(AiUsageRecorder::class)]
final class AiUsageRecorderTest extends TestCase
{
    private const NOW = '2026-10-20 09:00:00';

    private InMemoryAiUsageRepository $usages;
    private InMemoryIntegrationRepository $integrations;
    private TenantContext $tenantContext;
    private AiUsageRecorder $recorder;

    protected function setUp(): void
    {
        $this->usages = new InMemoryAiUsageRepository();
        $this->integrations = new InMemoryIntegrationRepository();
        $this->tenantContext = new TenantContext();
        $this->tenantContext->setOrganizationId(Uuid::v7());

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

        $this->recorder = new AiUsageRecorder(
            usageRepository: $this->usages,
            config: $config,
            prices: new AiPriceCatalog(),
            tenantContext: $this->tenantContext,
            clock: new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
            logger: new NullLogger(),
        );
    }

    public function testItRegistersTheTokensAndTheCostOfASuccessfulCall(): void
    {
        $provider = new FakeAiProvider('groq', AiTier::CHEAP, [AiPurpose::EXTRACTION], new AiResponse(
            content: '{}',
            data: [],
            promptTokens: 1_000_000,
            completionTokens: 1_000_000,
            model: 'llama-3.3-70b-versatile',
        ));

        $response = $this->recorder->record($provider, AiPurpose::EXTRACTION, static fn (): AiResponse => $provider->complete(
            new \App\Ai\Domain\ValueObject\AiRequest('sistema', 'usuario'),
        ));

        self::assertCount(1, $this->usages->all());

        $usage = $this->usages->all()[0];

        self::assertTrue($usage->isSuccessful());
        self::assertSame('groq', $usage->getProviderKey());
        self::assertSame(AiPurpose::EXTRACTION, $usage->getPurpose());
        self::assertSame(AiTier::CHEAP, $usage->getTier());
        self::assertSame('llama-3.3-70b-versatile', $usage->getModel());
        self::assertSame(1_000_000, $usage->getPromptTokens());
        self::assertSame(1_000_000, $usage->getCompletionTokens());
        // 0,59 + 0,79 = 1,38 € → 138 céntimos.
        self::assertSame(138, $usage->getCostMinor());
        self::assertSame($response->model, $usage->getModel());
    }

    public function testItRegistersTheFailureAndRethrows(): void
    {
        $provider = new FakeAiProvider(
            'groq',
            AiTier::CHEAP,
            [AiPurpose::EXTRACTION],
            null,
            AiProviderException::notConfigured('groq'),
        );

        try {
            $this->recorder->record($provider, AiPurpose::EXTRACTION, static fn (): AiResponse => $provider->complete(
                new \App\Ai\Domain\ValueObject\AiRequest('sistema', 'usuario'),
            ));

            self::fail('El fallo del proveedor debería propagarse.');
        } catch (AiProviderException) {
            // esperado
        }

        self::assertCount(1, $this->usages->all());

        $usage = $this->usages->all()[0];

        self::assertFalse($usage->isSuccessful());
        self::assertSame(0, $usage->getCostMinor());
        self::assertSame(0, $usage->getPromptTokens());
    }

    public function testItDoesNotRegisterAnythingWithoutAnOrganization(): void
    {
        $this->tenantContext->setOrganizationId(null);
        $provider = new FakeAiProvider('groq', AiTier::CHEAP, [AiPurpose::EXTRACTION], new AiResponse('{}'));

        $this->recorder->record($provider, AiPurpose::EXTRACTION, static fn (): AiResponse => $provider->complete(
            new \App\Ai\Domain\ValueObject\AiRequest('sistema', 'usuario'),
        ));

        self::assertSame(0, $this->usages->count());
    }

    public function testTheCostUsesThePriceOverriddenByTheOperator(): void
    {
        $integration = new \App\Integrations\Domain\Entity\Integration(
            $this->tenantContext->requireOrganizationId(),
            'groq',
            'Groq',
        );
        $integration->setSettings(['prompt_price' => '1.00', 'completion_price' => '2.00']);
        $this->integrations->add($integration);

        $provider = new FakeAiProvider('groq', AiTier::CHEAP, [AiPurpose::EXTRACTION], new AiResponse(
            content: '{}',
            promptTokens: 1_000_000,
            completionTokens: 1_000_000,
        ));

        $this->recorder->record($provider, AiPurpose::EXTRACTION, static fn (): AiResponse => $provider->complete(
            new \App\Ai\Domain\ValueObject\AiRequest('sistema', 'usuario'),
        ));

        // 1,00 + 2,00 = 3,00 € → 300 céntimos.
        self::assertSame(300, $this->usages->all()[0]->getCostMinor());
    }

    public function testTheEntityThatMotivatedTheCallIsRecorded(): void
    {
        $provider = new FakeAiProvider('groq', AiTier::CHEAP, [AiPurpose::EXTRACTION], new AiResponse('{}'));
        $entityId = Uuid::v7();

        $this->recorder->record(
            $provider,
            AiPurpose::EXTRACTION,
            static fn (): AiResponse => $provider->complete(new \App\Ai\Domain\ValueObject\AiRequest('sistema', 'usuario')),
            'email_message',
            $entityId,
        );

        $usage = $this->usages->all()[0];

        self::assertSame('email_message', $usage->getEntityType());
        self::assertTrue($entityId->equals($usage->getEntityId()));
    }
}
