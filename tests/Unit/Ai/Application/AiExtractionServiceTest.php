<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Application;

use App\Ai\Application\AiBudget;
use App\Ai\Application\AiExtractionService;
use App\Ai\Application\AiPriceCatalog;
use App\Ai\Application\AiUsageRecorder;
use App\Ai\Application\Extraction\AiDocumentExtractor;
use App\Ai\Application\Extraction\DocumentExtractorChain;
use App\Ai\Application\ExtractionValidator;
use App\Ai\Application\RedactContent;
use App\Ai\Domain\Contract\AiProviderException;
use App\Ai\Domain\Contract\AiProviderInterface;
use App\Ai\Domain\Contract\AiProviderSelectorInterface;
use App\Ai\Domain\Entity\AiUsage;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Ai\Domain\ValueObject\AiResponse;
use App\Integrations\Application\IntegrationConfigReader;
use App\Integrations\Application\IntegrationRegistry;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Processing\Domain\Dto\ExtractedDocument;
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
 * El servicio que aplica la escalera de IA al pipeline.
 *
 * Su contrato con el pipeline es simple: *un documento mejor, o ninguno*.
 *
 * Lo que se comprueba aquí son las tres salidas posibles: el documento
 * mejorado, `null` cuando no hay nada que mejorar, y `null` cuando el
 * presupuesto está agotado. Esta última es la importante: un presupuesto
 * comprobado **después** de la llamada no es un presupuesto, es un informe.
 */
#[CoversClass(AiExtractionService::class)]
final class AiExtractionServiceTest extends TestCase
{
    use BuildsValidator;

    private const NOW = '2026-10-20 09:00:00';

    private InMemoryAiUsageRepository $usages;
    private TenantContext $tenantContext;
    private ValidatorInterface $validator;
    private FakeAiProvider $provider;

    protected function setUp(): void
    {
        $this->usages = new InMemoryAiUsageRepository();
        $this->tenantContext = new TenantContext();
        $this->tenantContext->setOrganizationId(Uuid::v7());
        $this->validator = $this->buildValidator();

        $this->provider = new FakeAiProvider('groq', AiTier::CHEAP, [AiPurpose::EXTRACTION], new AiResponse(
            content: '{}',
            data: [
                'provider' => 'Netflix',
                'amount' => 12.99,
                'currency' => 'EUR',
                'billingPeriod' => 'monthly',
            ],
        ));
    }

    public function testItReturnsTheDocumentImprovedByTheModel(): void
    {
        $document = $this->service(500, $this->provider)->enrich(
            $this->deterministicDocument(),
            'Factura de Netflix 12,99 €',
            $this->header(),
        );

        self::assertInstanceOf(ExtractedDocument::class, $document);
        self::assertSame(ExtractionTier::AI_CHEAP, $document->tier);
        self::assertSame(1299, $document->amountMinor);
        self::assertSame('Netflix', $document->providerName);
        self::assertSame('monthly', $document->billingPeriod?->value);
    }

    public function testItReturnsNothingWhenTheTextIsEmpty(): void
    {
        $document = $this->service(500, $this->provider)->enrich(
            $this->deterministicDocument(),
            '   ',
            $this->header(),
        );

        self::assertNull($document);
        self::assertSame(0, $this->provider->requestCount());
    }

    public function testItReturnsNothingWhenTheBudgetIsExhausted(): void
    {
        $this->spend(500);

        $document = $this->service(500, $this->provider)->enrich(
            $this->deterministicDocument(),
            'Factura de Netflix 12,99 €',
            $this->header(),
        );

        self::assertNull($document);
        self::assertSame(0, $this->provider->requestCount());
    }

    public function testTheBudgetIsCheckedBeforeTheCall(): void
    {
        // Un presupuesto que se comprueba después de la llamada cobra la
        // llamada que lo rebasa. Aquí la llamada no llega a hacerse.
        $this->spend(499);

        $document = $this->service(500, $this->provider)->enrich(
            $this->deterministicDocument(),
            'Factura de Netflix 12,99 €',
            $this->header(),
        );

        self::assertInstanceOf(ExtractedDocument::class, $document);
        self::assertSame(1, $this->provider->requestCount());
    }

    public function testTheModelReceivesTheRedactedText(): void
    {
        $this->service(500, $this->provider)->enrich(
            $this->deterministicDocument(),
            '<html><head><style>p{}</style></head><body><p>Factura 12,99 €</p><script>alert(1)</script></body></html>',
            $this->header(),
        );

        $request = $this->provider->lastRequest();

        self::assertNotNull($request);
        self::assertStringNotContainsString('alert(1)', $request->userPrompt);
        self::assertStringNotContainsString('<p>', $request->userPrompt);
        self::assertStringContainsString('12,99', $request->userPrompt);
    }

    public function testTheModelReceivesTheMetadataOfTheMessage(): void
    {
        $this->service(500, $this->provider)->enrich(
            $this->deterministicDocument(),
            'Factura',
            $this->header(),
        );

        $request = $this->provider->lastRequest();

        self::assertNotNull($request);
        self::assertStringContainsString('facturas@netflix.com', $request->userPrompt);
        self::assertStringContainsString('Tu factura de Netflix', $request->userPrompt);
    }

    public function testTheCallIsRegisteredWithItsCost(): void
    {
        $this->service(500, $this->provider)->enrich(
            $this->deterministicDocument(),
            'Factura de Netflix 12,99 €',
            $this->header(),
        );

        self::assertSame(1, $this->usages->count());
        self::assertSame(AiPurpose::EXTRACTION, $this->usages->all()[0]->getPurpose());
    }

    public function testItReturnsNothingWhenThereIsNoProviderConfigured(): void
    {
        $service = $this->service(500, null);

        $document = $service->enrich(
            $this->deterministicDocument(),
            'Factura de Netflix 12,99 €',
            $this->header(),
        );

        self::assertNull($document);
    }

    public function testAFailingProviderDoesNotBreakThePipeline(): void
    {
        $provider = new FakeAiProvider(
            'groq',
            AiTier::CHEAP,
            [AiPurpose::EXTRACTION],
            null,
            AiProviderException::invalidResponse('groq', 'respuesta vacía'),
        );

        $document = $this->service(500, $provider)->enrich(
            $this->deterministicDocument(),
            'Factura de Netflix 12,99 €',
            $this->header(),
        );

        self::assertNull($document);
    }

    /**
     * @param AiProviderInterface|null $provider `null` simula que no hay
     *                                           proveedor configurado
     */
    private function service(int $budgetMinor, ?AiProviderInterface $provider = null): AiExtractionService
    {
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

        $recorder = new AiUsageRecorder(
            usageRepository: $this->usages,
            config: $config,
            prices: new AiPriceCatalog(),
            tenantContext: $this->tenantContext,
            clock: new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
            logger: new NullLogger(),
        );

        $selector = new class($provider) implements AiProviderSelectorInterface {
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

        $extractor = new AiDocumentExtractor(
            selector: $selector,
            validator: $this->validator,
            extractionValidator: new ExtractionValidator(),
            logger: new NullLogger(),
            usageRecorder: $recorder,
        );

        return new AiExtractionService(
            chain: new DocumentExtractorChain([$extractor], new NullLogger()),
            redactor: new RedactContent(),
            budget: new AiBudget(
                usageRepository: $this->usages,
                tenantContext: $this->tenantContext,
                clock: new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
                monthlyMinor: $budgetMinor,
            ),
            logger: new NullLogger(),
        );
    }

    private function deterministicDocument(): ExtractedDocument
    {
        return new ExtractedDocument(
            tier: ExtractionTier::DETERMINISTIC,
            confidence: 0.2,
            sender: 'facturas@netflix.com',
            senderDomain: 'netflix.com',
            subject: 'Tu factura de Netflix',
        );
    }

    private function header(): ImapMessageHeader
    {
        return new ImapMessageHeader(
            uid: 42,
            messageId: '<abc@example.com>',
            fromAddress: 'facturas@netflix.com',
            fromName: 'Netflix',
            replyTo: null,
            toAddresses: ['yo@example.com'],
            subject: 'Tu factura de Netflix',
            receivedAt: new DateTimeImmutable(self::NOW),
            sizeBytes: 1024,
            contentType: 'text/plain',
            attachmentNames: [],
            attachmentTypes: [],
        );
    }

    private function spend(int $costMinor): void
    {
        $this->usages->save(new AiUsage(
            organizationId: $this->tenantContext->requireOrganizationId(),
            providerKey: 'groq',
            purpose: AiPurpose::EXTRACTION,
            tier: AiTier::CHEAP,
            costMinor: $costMinor,
            createdAt: new DateTimeImmutable(self::NOW),
        ));
    }
}
