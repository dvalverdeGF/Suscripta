<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Application\Extraction;

use App\Ai\Application\AiPriceCatalog;
use App\Ai\Application\AiUsageRecorder;
use App\Ai\Application\Extraction\AdvancedAiDocumentExtractor;
use App\Ai\Application\Extraction\AiDocumentExtractor;
use App\Ai\Application\ExtractionValidator;
use App\Ai\Domain\Contract\AiProviderException;
use App\Ai\Domain\Contract\AiProviderInterface;
use App\Ai\Domain\Contract\AiProviderSelectorInterface;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Ai\Domain\ValueObject\AiResponse;
use App\Ai\Domain\ValueObject\ExtractionContext;
use App\Integrations\Application\IntegrationConfigReader;
use App\Integrations\Application\IntegrationRegistry;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Ai\FakeAiProvider;
use App\Tests\Support\Ai\InMemoryAiUsageRepository;
use App\Tests\Support\BuildsValidator;
use App\Tests\Support\Integrations\InMemoryIntegrationRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Los niveles 5 y 6 de la escalera, y las reglas de negocio del nivel 7.
 *
 * Lo que se comprueba aquí es la **frontera entre el modelo y el código**:
 * el modelo lee, el código decide. Un importe en euros se convierte a
 * céntimos; una periodicidad se interpreta con las mismas reglas que el resto
 * del sistema; y una respuesta que no tiene sentido (importe negativo,
 * renovación anterior a la factura) se **rechaza** en vez de guardarse.
 */
#[CoversClass(AiDocumentExtractor::class)]
#[CoversClass(AdvancedAiDocumentExtractor::class)]
#[CoversClass(ExtractionValidator::class)]
final class AiExtractionTest extends TestCase
{
    use BuildsValidator;

    private const NOW = '2026-10-20 09:00:00';

    private InMemoryAiUsageRepository $usages;
    private ValidatorInterface $validator;
    private AiUsageRecorder $recorder;

    protected function setUp(): void
    {
        $this->usages = new InMemoryAiUsageRepository();
        $this->validator = $this->buildValidator();

        $integrations = new InMemoryIntegrationRepository();
        $config = new IntegrationConfigReader(
            $integrations,
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

        $tenantContext = new TenantContext();
        $tenantContext->setOrganizationId(Uuid::v7());

        $this->recorder = new AiUsageRecorder(
            usageRepository: $this->usages,
            config: $config,
            prices: new AiPriceCatalog(),
            tenantContext: $tenantContext,
            clock: new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
            logger: new NullLogger(),
        );
    }

    // ── Nivel 5: cuándo entra ───────────────────────────────────────────────

    public function testTheCheapLevelDoesNotEnterWithoutText(): void
    {
        $extractor = $this->cheapExtractor($this->selectorWith(new FakeAiProvider('groq')));

        self::assertFalse($extractor->supports(new ExtractionContext(text: '   ')));
    }

    public function testTheCheapLevelDoesNotEnterWhenTheAmountIsAlreadyKnown(): void
    {
        $extractor = $this->cheapExtractor($this->selectorWith(new FakeAiProvider('groq')));

        $context = new ExtractionContext(text: 'Importe 12,99 €', deterministic: ['amountMinor' => 1299]);

        self::assertFalse($extractor->supports($context));
    }

    public function testTheCheapLevelEntersWhenTheAmountIsMissing(): void
    {
        $extractor = $this->cheapExtractor($this->selectorWith(new FakeAiProvider('groq')));

        self::assertTrue($extractor->supports(new ExtractionContext(text: 'Importe 12,99 €')));
    }

    public function testTheCheapLevelDoesNotEnterWithoutAProvider(): void
    {
        $extractor = $this->cheapExtractor($this->selectorWith(null));

        self::assertFalse($extractor->supports(new ExtractionContext(text: 'Importe 12,99 €')));
    }

    // ── Nivel 5: qué hace con la respuesta ─────────────────────────────────

    public function testItBuildsTheDocumentFromTheModelAnswer(): void
    {
        $provider = new FakeAiProvider('groq', AiTier::CHEAP, [AiPurpose::EXTRACTION], new AiResponse(
            content: '{"provider":"Netflix","amount":12.99,"currency":"EUR","billingPeriod":"monthly","confidence":0.9}',
            data: [
                'provider' => 'Netflix',
                'amount' => 12.99,
                'currency' => 'EUR',
                'billingPeriod' => 'monthly',
                'confidence' => 0.9,
            ],
            promptTokens: 100,
            completionTokens: 50,
        ));

        $extractor = $this->cheapExtractor($this->selectorWith($provider));

        $document = $extractor->extract(new ExtractionContext(text: 'Factura de Netflix 12,99 €'));

        self::assertSame(ExtractionTier::AI_CHEAP, $document->tier);
        self::assertSame(1299, $document->amountMinor);
        self::assertSame('Netflix', $document->providerName);
        self::assertSame('monthly', $document->billingPeriod?->value);
        self::assertTrue($document->isActionable());
    }

    public function testItKeepsTheDeterministicDataTheModelDidNotReturn(): void
    {
        $provider = new FakeAiProvider('groq', AiTier::CHEAP, [AiPurpose::EXTRACTION], new AiResponse(
            content: '{}',
            data: [
                'provider' => 'Netflix',
                'amount' => 12.99,
            ],
        ));

        $extractor = $this->cheapExtractor($this->selectorWith($provider));

        $document = $extractor->extract(new ExtractionContext(
            text: 'Factura',
            deterministic: ['serviceName' => 'Plan Premium', 'senderDomain' => 'netflix.com'],
        ));

        self::assertSame('Plan Premium', $document->serviceName);
        self::assertSame('netflix.com', $document->senderDomain);
    }

    public function testTheCallIsRegisteredWithItsCost(): void
    {
        $provider = new FakeAiProvider('groq', AiTier::CHEAP, [AiPurpose::EXTRACTION], new AiResponse(
            content: '{}',
            data: ['provider' => 'Netflix', 'amount' => 12.99, 'billingPeriod' => 'monthly'],
            promptTokens: 1000,
            completionTokens: 500,
        ));

        $extractor = $this->cheapExtractor($this->selectorWith($provider));
        $extractor->extract(new ExtractionContext(text: 'Factura'));

        self::assertSame(1, $this->usages->count());
        self::assertSame(AiPurpose::EXTRACTION, $this->usages->all()[0]->getPurpose());
        self::assertSame(AiTier::CHEAP, $this->usages->all()[0]->getTier());
    }

    public function testTheModelReceivesTheDeterministicDataAsContext(): void
    {
        $provider = new FakeAiProvider('groq', AiTier::CHEAP, [AiPurpose::EXTRACTION], new AiResponse(
            content: '{}',
            data: ['provider' => 'Netflix', 'amount' => 12.99],
        ));

        $extractor = $this->cheapExtractor($this->selectorWith($provider));
        $extractor->extract(new ExtractionContext(
            text: 'Factura',
            deterministic: ['serviceName' => 'Plan Premium'],
            metadata: ['subject' => 'Tu factura'],
        ));

        $request = $provider->lastRequest();

        self::assertNotNull($request);
        self::assertStringContainsString('Plan Premium', $request->userPrompt);
        self::assertStringContainsString('Tu factura', $request->userPrompt);
    }

    // ── Nivel 7: la salida inválida se rechaza ─────────────────────────────

    public function testAnEmptyAnswerIsRejected(): void
    {
        $provider = new FakeAiProvider('groq', AiTier::CHEAP, [AiPurpose::EXTRACTION], new AiResponse(content: 'nada'));
        $extractor = $this->cheapExtractor($this->selectorWith($provider));

        $this->expectException(AiProviderException::class);

        $extractor->extract(new ExtractionContext(text: 'Factura'));
    }

    public function testANegativeAmountIsRejected(): void
    {
        $provider = new FakeAiProvider('groq', AiTier::CHEAP, [AiPurpose::EXTRACTION], new AiResponse(
            content: '{}',
            data: ['provider' => 'Netflix', 'amount' => -12.99],
        ));

        $extractor = $this->cheapExtractor($this->selectorWith($provider));

        $this->expectException(AiProviderException::class);

        $extractor->extract(new ExtractionContext(text: 'Factura'));
    }

    public function testARenewalBeforeTheInvoiceIsRejected(): void
    {
        $provider = new FakeAiProvider('groq', AiTier::CHEAP, [AiPurpose::EXTRACTION], new AiResponse(
            content: '{}',
            data: [
                'provider' => 'Netflix',
                'amount' => 12.99,
                'billingPeriod' => 'monthly',
                'invoiceDate' => '2026-10-01',
                'renewalDate' => '2026-09-01',
            ],
        ));

        $extractor = $this->cheapExtractor($this->selectorWith($provider));

        $this->expectException(AiProviderException::class);

        $extractor->extract(new ExtractionContext(text: 'Factura'));
    }

    public function testAnAbsurdAmountIsRejected(): void
    {
        $provider = new FakeAiProvider('groq', AiTier::CHEAP, [AiPurpose::EXTRACTION], new AiResponse(
            content: '{}',
            data: ['provider' => 'Netflix', 'amount' => 1_299_000],
        ));

        $extractor = $this->cheapExtractor($this->selectorWith($provider));

        $this->expectException(AiProviderException::class);

        $extractor->extract(new ExtractionContext(text: 'Factura'));
    }

    public function testAnUnknownPeriodIsRejected(): void
    {
        $provider = new FakeAiProvider('groq', AiTier::CHEAP, [AiPurpose::EXTRACTION], new AiResponse(
            content: '{}',
            data: ['provider' => 'Netflix', 'amount' => 12.99, 'billingPeriod' => 'cada cuando'],
        ));

        $extractor = $this->cheapExtractor($this->selectorWith($provider));

        $this->expectException(AiProviderException::class);

        $extractor->extract(new ExtractionContext(text: 'Factura'));
    }

    // ── Nivel 6: cuándo escala ─────────────────────────────────────────────

    public function testTheAdvancedLevelDoesNotEnterWithoutHavingUsedTheCheapOne(): void
    {
        $extractor = $this->advancedExtractor($this->selectorWith(new FakeAiProvider('openai', AiTier::ADVANCED)));

        self::assertFalse($extractor->supports(new ExtractionContext(text: 'Factura')));
    }

    public function testTheAdvancedLevelEntersWhenThePeriodIsMissing(): void
    {
        $extractor = $this->advancedExtractor($this->selectorWith(new FakeAiProvider('openai', AiTier::ADVANCED)));

        $context = new ExtractionContext(
            text: 'Factura',
            deterministic: ['amountMinor' => 1299, 'providerName' => 'Netflix'],
            currentTier: ExtractionTier::AI_CHEAP,
        );

        self::assertTrue($extractor->supports($context));
    }

    public function testTheAdvancedLevelEntersWhenTheProviderIsMissing(): void
    {
        $extractor = $this->advancedExtractor($this->selectorWith(new FakeAiProvider('openai', AiTier::ADVANCED)));

        $context = new ExtractionContext(
            text: 'Factura',
            deterministic: ['amountMinor' => 1299, 'billingPeriod' => 'monthly'],
            currentTier: ExtractionTier::AI_CHEAP,
        );

        self::assertTrue($extractor->supports($context));
    }

    public function testTheAdvancedLevelEntersWhenTheDeterministicConfidenceIsTooLow(): void
    {
        $extractor = $this->advancedExtractor($this->selectorWith(new FakeAiProvider('openai', AiTier::ADVANCED)));

        $context = new ExtractionContext(
            text: 'Factura',
            deterministic: ['confidence' => 0.1],
            currentTier: ExtractionTier::AI_CHEAP,
        );

        self::assertTrue($extractor->supports($context));
    }

    public function testTheAdvancedLevelDoesNotEnterWhenEverythingIsAlreadyKnown(): void
    {
        $extractor = $this->advancedExtractor($this->selectorWith(new FakeAiProvider('openai', AiTier::ADVANCED)));

        $context = new ExtractionContext(
            text: 'Factura',
            deterministic: [
                'amountMinor' => 1299,
                'providerName' => 'Netflix',
                'billingPeriod' => 'monthly',
                'confidence' => 0.9,
            ],
            currentTier: ExtractionTier::AI_CHEAP,
        );

        self::assertFalse($extractor->supports($context));
    }

    public function testTheAdvancedLevelDoesNotEnterWithoutAnAdvancedProvider(): void
    {
        $extractor = $this->advancedExtractor($this->selectorWith(new FakeAiProvider('groq', AiTier::CHEAP)));

        $context = new ExtractionContext(
            text: 'Factura',
            deterministic: ['amountMinor' => 1299],
            currentTier: ExtractionTier::AI_CHEAP,
        );

        self::assertFalse($extractor->supports($context));
    }

    public function testTheAdvancedLevelDoesNotEnterWithACheapProviderEvenIfThePeriodIsMissing(): void
    {
        // El caso que hace falta para que la guarda de nivel se note: sin
        // ella, este documento entraría en el nivel 6 y se pagaría una
        // llamada «avanzada» con un modelo barato.
        $extractor = $this->advancedExtractor($this->selectorWith(new FakeAiProvider('groq', AiTier::CHEAP)));

        $context = new ExtractionContext(
            text: 'Factura',
            deterministic: ['amountMinor' => 1299, 'providerName' => 'Netflix'],
            currentTier: ExtractionTier::AI_CHEAP,
        );

        self::assertFalse($extractor->supports($context));
    }

    public function testTheAdvancedLevelEntersWithAnAdvancedProviderWhenThePeriodIsMissing(): void
    {
        $extractor = $this->advancedExtractor($this->selectorWith(new FakeAiProvider('openai', AiTier::ADVANCED)));

        $context = new ExtractionContext(
            text: 'Factura',
            deterministic: ['amountMinor' => 1299, 'providerName' => 'Netflix'],
            currentTier: ExtractionTier::AI_CHEAP,
        );

        self::assertTrue($extractor->supports($context));
    }

    public function testTheAdvancedLevelDoesNotEnterWhenTheProviderCannotBeResolved(): void
    {
        $extractor = $this->advancedExtractor($this->selectorWith(null));

        $context = new ExtractionContext(
            text: 'Factura',
            deterministic: ['amountMinor' => 1299],
            currentTier: ExtractionTier::AI_CHEAP,
        );

        self::assertFalse($extractor->supports($context));
    }

    public function testTheAdvancedLevelDoesNotEnterWhenTheProviderFailsToResolve(): void
    {
        // Un selector que dice que sí hay proveedor pero falla al resolverlo
        // no debe dejar pasar el nivel 6: la llamada se quedaría sin proveedor
        // y reventaría en mitad de la extracción.
        $selector = new class implements AiProviderSelectorInterface {
            public function forPurpose(AiPurpose $purpose): AiProviderInterface
            {
                throw AiProviderException::notAvailableForPurpose($purpose->value);
            }

            public function advanced(): AiProviderInterface
            {
                throw AiProviderException::noAdvancedProvider();
            }

            public function has(AiPurpose $purpose): bool
            {
                return true;
            }

            public function configuredKeys(): array
            {
                return ['openai'];
            }
        };

        $extractor = $this->advancedExtractor($selector);

        $context = new ExtractionContext(
            text: 'Factura',
            deterministic: ['amountMinor' => 1299],
            currentTier: ExtractionTier::AI_CHEAP,
        );

        self::assertFalse($extractor->supports($context));
    }

    public function testTheAdvancedLevelUsesTheProviderOfTheAdvancedPurpose(): void
    {
        $provider = new FakeAiProvider('openai', AiTier::ADVANCED, [AiPurpose::ADVANCED_EXTRACTION], new AiResponse(
            content: '{}',
            data: [
                'provider' => 'Netflix',
                'amount' => 12.99,
                'billingPeriod' => 'monthly',
            ],
        ));

        $extractor = $this->advancedExtractor($this->selectorWith($provider));

        $document = $extractor->extract(new ExtractionContext(
            text: 'Factura',
            deterministic: ['amountMinor' => 1299],
            currentTier: ExtractionTier::AI_CHEAP,
        ));

        self::assertSame(ExtractionTier::AI_ADVANCED, $document->tier);
        self::assertSame(1, $this->usages->count());
        self::assertSame(AiPurpose::ADVANCED_EXTRACTION, $this->usages->all()[0]->getPurpose());
        self::assertSame(AiTier::ADVANCED, $this->usages->all()[0]->getTier());
    }

    private function cheapExtractor(AiProviderSelectorInterface $selector): AiDocumentExtractor
    {
        return new AiDocumentExtractor(
            selector: $selector,
            validator: $this->validator,
            extractionValidator: new ExtractionValidator(),
            logger: new NullLogger(),
            usageRecorder: $this->recorder,
        );
    }

    private function advancedExtractor(AiProviderSelectorInterface $selector): AdvancedAiDocumentExtractor
    {
        return new AdvancedAiDocumentExtractor(
            selector: $selector,
            validator: $this->validator,
            extractionValidator: new ExtractionValidator(),
            logger: new NullLogger(),
            usageRecorder: $this->recorder,
            confidenceThreshold: 0.3,
            maxTextLength: 8000,
        );
    }

    /**
     * Selector que devuelve un único proveedor para todas las necesidades.
     */
    private function selectorWith(?AiProviderInterface $provider): AiProviderSelectorInterface
    {
        return new class($provider) implements AiProviderSelectorInterface {
            public function __construct(private readonly ?AiProviderInterface $provider)
            {
            }

            public function forPurpose(AiPurpose $purpose): AiProviderInterface
            {
                if (null === $this->provider) {
                    throw AiProviderException::notAvailableForPurpose($purpose->value);
                }

                return $this->provider;
            }

            public function advanced(): AiProviderInterface
            {
                if (null === $this->provider) {
                    throw AiProviderException::noAdvancedProvider();
                }

                return $this->provider;
            }

            public function has(AiPurpose $purpose): bool
            {
                return null !== $this->provider;
            }

            public function configuredKeys(): array
            {
                return null !== $this->provider ? [$this->provider->key()] : [];
            }
        };
    }
}
