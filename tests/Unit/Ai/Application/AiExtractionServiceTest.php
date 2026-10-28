<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Application;

use App\Ai\Application\AiBudgetGuard;
use App\Ai\Application\AiCallRateLimiter;
use App\Ai\Application\AiExtractionService;
use App\Ai\Application\AiPriceCatalog;
use App\Ai\Application\AiUsageRecorder;
use App\Ai\Application\Extraction\AiDocumentExtractor;
use App\Ai\Application\Extraction\DocumentExtractorChain;
use App\Ai\Application\ExtractionValidator;
use App\Ai\Application\RedactContent;
use App\Ai\Domain\Contract\AiProviderException;
use App\Ai\Domain\Contract\AiProviderInterface;
use App\Ai\Domain\Entity\AiUsage;
use App\Ai\Domain\Enum\AiBudgetMode;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiSkipReason;
use App\Ai\Domain\Enum\AiTier;
use App\Ai\Domain\ValueObject\AiCost;
use App\Ai\Domain\ValueObject\AiResponse;
use App\Integrations\Application\IntegrationConfigReader;
use App\Integrations\Application\IntegrationRegistry;
use App\Mailbox\Application\CredentialCipherInterface;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Processing\Domain\Dto\ExtractedDocument;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Ai\FakeAiProvider;
use App\Tests\Support\Ai\FakeAiProviderSelector;
use App\Tests\Support\Ai\InMemoryAiBudgetRepository;
use App\Tests\Support\Ai\InMemoryAiUsageRepository;
use App\Tests\Support\BuildsValidator;
use App\Tests\Support\Entitlements\InMemoryEntitlementChecker;
use App\Tests\Support\Integrations\InMemoryIntegrationRepository;
use App\Webhooks\Application\WebhookNotifier;
use App\Webhooks\Domain\Entity\Webhook;
use App\Webhooks\Domain\Enum\WebhookEvent;
use App\Webhooks\Domain\Repository\WebhookDeliveryRepositoryInterface;
use App\Webhooks\Domain\Repository\WebhookRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * El servicio que aplica la escalera de IA al pipeline.
 *
 * Su contrato con el pipeline es simple: *un documento mejor, o el motivo por
 * el que no lo hay*.
 *
 * Lo que se comprueba aquí son las cuatro salidas posibles: el documento
 * mejorado, el motivo `NO_TEXT` cuando no hay nada que enviar, el motivo
 * `BUDGET_EXHAUSTED` cuando el presupuesto se ha agotado, y el motivo
 * `NOT_CONFIGURED` cuando la organización no ha elegido proveedor. Los dos
 * últimos son los importantes: un presupuesto comprobado **después** de la
 * llamada no es un presupuesto, es un informe, y confundir «no hay IA
 * configurada» con «el presupuesto se ha agotado» haría que el pipeline
 * tratara de reintentar lo que no se puede reintentar.
 */
#[CoversClass(AiExtractionService::class)]
final class AiExtractionServiceTest extends TestCase
{
    use BuildsValidator;

    private const NOW = '2026-10-20 09:00:00';

    private InMemoryAiUsageRepository $usages;
    private InMemoryAiBudgetRepository $budgets;
    private TenantContext $tenantContext;
    private ValidatorInterface $validator;
    private FakeAiProvider $provider;

    protected function setUp(): void
    {
        $this->usages = new InMemoryAiUsageRepository();
        $this->budgets = new InMemoryAiBudgetRepository();
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
        $outcome = $this->service(500, $this->provider)->enrich(
            $this->deterministicDocument(),
            'Factura de Netflix 12,99 €',
            $this->header(),
        );

        self::assertTrue($outcome->hasDocument());
        self::assertSame(AiSkipReason::NONE, $outcome->reason);
        self::assertFalse($outcome->isSkipped());

        $document = $outcome->document;

        self::assertInstanceOf(ExtractedDocument::class, $document);
        self::assertSame(ExtractionTier::AI_CHEAP, $document->tier);
        self::assertSame(1299, $document->amountMinor);
        self::assertSame('Netflix', $document->providerName);
        self::assertSame('monthly', $document->billingPeriod?->value);
    }

    public function testItSkipsWhenTheTextIsEmpty(): void
    {
        $outcome = $this->service(500, $this->provider)->enrich(
            $this->deterministicDocument(),
            '   ',
            $this->header(),
        );

        self::assertTrue($outcome->isSkipped());
        self::assertSame(AiSkipReason::NO_TEXT, $outcome->reason);
        self::assertSame(0, $this->provider->requestCount());
    }

    public function testItSkipsWhenTheBudgetIsExhausted(): void
    {
        $this->spend(500);

        $outcome = $this->service(500, $this->provider, AiBudgetMode::HARD)->enrich(
            $this->deterministicDocument(),
            'Factura de Netflix 12,99 €',
            $this->header(),
        );

        self::assertTrue($outcome->isSkipped());
        self::assertSame(AiSkipReason::BUDGET_EXHAUSTED, $outcome->reason);
        self::assertSame(0, $this->provider->requestCount());
    }

    public function testTheBudgetIsCheckedBeforeTheCall(): void
    {
        // Un presupuesto que se comprueba después de la llamada cobra la
        // llamada que lo rebasa. Aquí la llamada no llega a hacerse.
        $this->spend(499);

        $outcome = $this->service(500, $this->provider, AiBudgetMode::HARD)->enrich(
            $this->deterministicDocument(),
            'Factura de Netflix 12,99 €',
            $this->header(),
        );

        self::assertTrue($outcome->hasDocument());
        self::assertSame(1, $this->provider->requestCount());
    }

    public function testInSoftModeTheCheapModelKeepsWorkingAfterTheBudgetIsExhausted(): void
    {
        // En modo blando, agotar el presupuesto apaga el nivel avanzado, no
        // la IA entera: es lo que hace útil el modo para un cliente que
        // prefiere ahorrar a dejar de funcionar.
        $this->spend(500);

        $outcome = $this->service(500, $this->provider, AiBudgetMode::SOFT)->enrich(
            $this->deterministicDocument(),
            'Factura de Netflix 12,99 €',
            $this->header(),
        );

        self::assertTrue($outcome->hasDocument());
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

    public function testItSkipsWhenThereIsNoProviderConfigured(): void
    {
        $outcome = $this->service(500, null)->enrich(
            $this->deterministicDocument(),
            'Factura de Netflix 12,99 €',
            $this->header(),
        );

        self::assertTrue($outcome->isSkipped());
        self::assertSame(AiSkipReason::NOT_CONFIGURED, $outcome->reason);
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

        $outcome = $this->service(500, $provider)->enrich(
            $this->deterministicDocument(),
            'Factura de Netflix 12,99 €',
            $this->header(),
        );

        self::assertTrue($outcome->isSkipped());
        self::assertFalse($outcome->defersMessage());
    }

    public function testOnlyTheExhaustedBudgetDefersTheMessage(): void
    {
        // Solo el presupuesto agotado aplaza el correo. Los demás motivos son
        // fallos o decisiones, y un correo que se aplaza esperando vuelve al
        // pipeline, mientras que un correo que va a revisión no.
        $noText = $this->service(500, $this->provider)->enrich(
            $this->deterministicDocument(),
            '   ',
            $this->header(),
        );

        self::assertFalse($noText->defersMessage());

        $this->spend(500);

        $exhausted = $this->service(500, $this->provider, AiBudgetMode::HARD)->enrich(
            $this->deterministicDocument(),
            'Factura de Netflix 12,99 €',
            $this->header(),
        );

        self::assertTrue($exhausted->defersMessage());
    }

    public function testTheExhaustedBudgetIsAnnouncedOncePerPeriodAndNotPerEmail(): void
    {
        $this->spend(500);

        $announcements = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$announcements) {
            $announcements[] = $message;

            return new Envelope($message);
        });

        $webhooks = $this->createMock(WebhookRepositoryInterface::class);
        $webhooks->method('findActiveSubscribers')->willReturnCallback(
            fn (Uuid $organizationId, WebhookEvent $event): array => WebhookEvent::AI_BUDGET_EXHAUSTED === $event ? [$this->subscriber()] : [],
        );

        $notifier = new WebhookNotifier(
            $webhooks,
            $this->createMock(WebhookDeliveryRepositoryInterface::class),
            $bus,
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
            new NullLogger(),
        );

        $cache = new ArrayAdapter();
        $service = $this->service(500, $this->provider, AiBudgetMode::HARD, $notifier, $cache);

        // Tres correos con el presupuesto ya agotado: tres pasadas por el
        // servicio, un solo aviso. Sin el freno serían tres y el suscriptor
        // acabaría ignorándolos.
        for ($i = 0; $i < 3; ++$i) {
            $service->enrich($this->deterministicDocument(), 'Factura de Netflix 12,99 €', $this->header());
        }

        self::assertCount(1, $announcements);
    }

    private function subscriber(): Webhook
    {
        return new Webhook(
            organizationId: $this->tenantContext->requireOrganizationId(),
            name: 'ERP',
            url: 'https://erp.example.com/hooks',
            secretEncrypted: 'whsec_c2VjcmV0bw',
            events: [WebhookEvent::AI_BUDGET_EXHAUSTED],
        );
    }

    /**
     * @param AiProviderInterface|null $provider `null` simula que no hay
     *                                           proveedor configurado
     */
    private function service(
        int $budgetMinor,
        ?AiProviderInterface $provider = null,
        AiBudgetMode $mode = AiBudgetMode::SOFT,
        ?WebhookNotifier $webhooks = null,
        ?CacheItemPoolInterface $cache = null,
    ): AiExtractionService {
        $integrations = new InMemoryIntegrationRepository();
        $config = new IntegrationConfigReader(
            $integrations,
            new IntegrationRegistry(),
            new class implements CredentialCipherInterface {
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

        $clock = new Clock(new MockClock(new DateTimeImmutable(self::NOW)));

        $recorder = new AiUsageRecorder(
            usageRepository: $this->usages,
            config: $config,
            prices: new AiPriceCatalog(),
            tenantContext: $this->tenantContext,
            clock: $clock,
            logger: new NullLogger(),
        );

        $extractor = new AiDocumentExtractor(
            selector: new FakeAiProviderSelector($provider),
            validator: $this->validator,
            extractionValidator: new ExtractionValidator(),
            logger: new NullLogger(),
            usageRecorder: $recorder,
        );

        return new AiExtractionService(
            chain: new DocumentExtractorChain([$extractor], new NullLogger()),
            redactor: new RedactContent(),
            budget: new AiBudgetGuard(
                budgets: $this->budgets,
                usage: $this->usages,
                tenantContext: $this->tenantContext,
                clock: $clock,
                entitlements: InMemoryEntitlementChecker::allowingEverything(),
                defaultLimitMinor: $budgetMinor,
                defaultMode: $mode,
            ),
            rateLimiter: new AiCallRateLimiter(
                limiter: new RateLimiterFactory(
                    [
                        'id' => 'ai_calls',
                        'policy' => 'sliding_window',
                        'limit' => 100,
                        'interval' => '1 minute',
                    ],
                    new InMemoryStorage(),
                ),
                tenantContext: $this->tenantContext,
            ),
            selector: new FakeAiProviderSelector($provider),
            logger: new NullLogger(),
            webhooks: $webhooks,
            cache: $cache,
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
            costMicro: AiCost::toMicro($costMinor),
            createdAt: new DateTimeImmutable(self::NOW),
        ));
    }
}
