<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application;

use App\Ai\Application\AiExtractionInterface;
use App\Ai\Application\NullAiExtraction;
use App\Catalog\Domain\Entity\Provider;
use App\Catalog\Domain\Entity\ProviderIdentity;
use App\Catalog\Domain\Enum\ProviderIdentityType;
use App\Catalog\Domain\Repository\ProviderIdentityRepositoryInterface;
use App\Catalog\Domain\Repository\ProviderParserRepositoryInterface;
use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Discovery\Application\DiscoverySettings;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Entity\DiscoveryEvidence;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Mailbox\Application\CredentialCipherInterface;
use App\Mailbox\Application\Imap\ImapClientInterface;
use App\Mailbox\Application\Imap\ImapConnectionConfig;
use App\Mailbox\Application\Imap\ImapMessageBody;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Entity\MessageProcessingEvent;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Mailbox\Domain\Repository\MessageProcessingEventRepositoryInterface;
use App\Processing\Application\Billing\BillingScoreCalculator;
use App\Processing\Application\Billing\BillingScoreWeights;
use App\Processing\Application\Extraction\AmountParser;
use App\Processing\Application\Extraction\DateParser;
use App\Processing\Application\Extraction\DeterministicExtractor;
use App\Processing\Application\Extraction\InvoiceNumberReader;
use App\Processing\Application\Knowledge\LearnFromMessage;
use App\Processing\Application\Knowledge\ResolveMailboxKnowledge;
use App\Processing\Application\Matching\ServiceMatcher;
use App\Processing\Application\Matching\ServiceMatchWeights;
use App\Processing\Application\MessageStateMachine;
use App\Processing\Application\ProcessEmailMessage;
use App\Processing\Application\Provider\ExtractWithProviderParser;
use App\Processing\Application\Provider\ProviderParserRegistry;
use App\Processing\Application\Provider\ProviderResolver;
use App\Processing\Application\Text\DocumentTextExtractorRegistry;
use App\Processing\Application\Text\ExtractDocumentText;
use App\Processing\Domain\Dto\ExtractedDocument;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Processing\Infrastructure\Ocr\NullOcrEngine;
use App\Processing\Infrastructure\Text\PdfTextExtractor;
use App\Processing\Infrastructure\Text\PlainTextExtractor;
use App\Services\Domain\Dto\ServiceView;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Ai\RecordingAiExtraction;
use App\Tests\Support\Processing\InMemoryExtractionCacheRepository;
use App\Tests\Support\Processing\PdfFixture;

use function count;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * El orquestador es el único punto donde se encadenan filtro, extracción,
 * emparejamiento y propuesta, y por eso es también el único sitio donde se
 * decide cuándo no se llama a IA.
 *
 * Dos invariantes que no se negocian: nunca se crea un `Service`
 * automáticamente (D-35) y el cuerpo del correo no se persiste (D-10).
 */
#[CoversClass(ProcessEmailMessage::class)]
final class ProcessEmailMessageTest extends TestCase
{
    private Uuid $organizationId;
    private Uuid $accountId;

    private EmailMessageRepositoryInterface&MockObject $messages;

    private EmailAccountRepositoryInterface&MockObject $accounts;

    private DiscoveryRepositoryInterface&MockObject $discoveries;

    private MessageProcessingEventRepositoryInterface&MockObject $events;

    private ImapClientInterface&MockObject $imap;

    private CredentialCipherInterface&MockObject $cipher;

    private AuditLoggerInterface&MockObject $audit;

    private ProviderIdentityRepositoryInterface&MockObject $identities;

    private ProviderParserRepositoryInterface&MockObject $parsers;

    private ServiceViewRepositoryInterface&MockObject $services;

    private DocumentRepositoryInterface&MockObject $documents;

    private InMemoryExtractionCacheRepository $cache;

    /** @var list<ServiceView> */
    private array $serviceViews = [];

    /** @var list<Discovery> */
    private array $savedDiscoveries = [];

    /** @var list<DiscoveryEvidence> */
    private array $savedEvidence = [];

    /** @var list<MessageProcessingEvent> */
    private array $savedEvents = [];

    /** @var list<AuditAction> */
    private array $audited = [];

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->accountId = Uuid::v7();

        $this->messages = $this->createMock(EmailMessageRepositoryInterface::class);
        $this->accounts = $this->createMock(EmailAccountRepositoryInterface::class);
        $this->discoveries = $this->createMock(DiscoveryRepositoryInterface::class);
        $this->events = $this->createMock(MessageProcessingEventRepositoryInterface::class);
        $this->imap = $this->createMock(ImapClientInterface::class);
        $this->cipher = $this->createMock(CredentialCipherInterface::class);
        $this->audit = $this->createMock(AuditLoggerInterface::class);
        $this->identities = $this->createMock(ProviderIdentityRepositoryInterface::class);
        $this->parsers = $this->createMock(ProviderParserRepositoryInterface::class);
        $this->services = $this->createMock(ServiceViewRepositoryInterface::class);
        $this->documents = $this->createMock(DocumentRepositoryInterface::class);
        $this->cache = new InMemoryExtractionCacheRepository();

        $this->serviceViews = [];

        $this->services
            ->method('findAll')
            ->willReturnCallback(fn (ServiceFilters $filters): array => $this->serviceViews);

        $this->savedDiscoveries = [];
        $this->savedEvidence = [];
        $this->savedEvents = [];
        $this->audited = [];

        $this->discoveries
            ->method('save')
            ->willReturnCallback(function (Discovery $discovery): void {
                $this->savedDiscoveries[] = $discovery;
            });

        $this->discoveries
            ->method('saveEvidence')
            ->willReturnCallback(function (DiscoveryEvidence $evidence): void {
                $this->savedEvidence[] = $evidence;
            });

        $this->events
            ->method('save')
            ->willReturnCallback(function (MessageProcessingEvent $event): void {
                $this->savedEvents[] = $event;
            });

        $this->audit
            ->method('log')
            ->willReturnCallback(function (AuditAction $action): void {
                $this->audited[] = $action;
            });
    }

    /**
     * @param list<Service> $services
     */
    private function orchestrator(array $services = [], ?AiExtractionInterface $aiExtraction = null): ProcessEmailMessage
    {
        $this->serviceViews = array_map(
            static fn (Service $service): ServiceView => ServiceView::fromService($service),
            $services,
        );

        $clock = new Clock(new MockClock(new DateTimeImmutable('2026-10-05 10:00:00')));

        return new ProcessEmailMessage(
            messages: $this->messages,
            accounts: $this->accounts,
            billingScore: new BillingScoreCalculator(BillingScoreWeights::fromArray([]), $this->identities),
            extractor: new DeterministicExtractor(new AmountParser(), new DateParser(), new InvoiceNumberReader(), $this->identities, $clock),
            documentText: $this->documentText(),
            providerResolver: new ProviderResolver($this->identities, $clock),
            providerParser: new ExtractWithProviderParser($this->parsers, new ProviderParserRegistry([]), $clock),
            matcher: new ServiceMatcher($this->services, ServiceMatchWeights::default()),
            discoveries: $this->discoveries,
            documents: $this->documents,
            discoverySettings: new DiscoverySettings(),
            stateMachine: new MessageStateMachine($this->events, $clock),
            imapClient: $this->imap,
            cipher: $this->cipher,
            extractionCache: $this->cache,
            tenantContext: new TenantContext(),
            auditLogger: $this->audit,
            clock: $clock,
            aiExtraction: $aiExtraction ?? new NullAiExtraction(),
            knowledgeResolver: $this->knowledgeResolver($clock),
            learnFromMessage: $this->learnFromMessage($clock),
        );
    }

    /**
     * El conocimiento del buzón arranca vacío: estos tests miden el pipeline,
     * no el aprendizaje, que tiene su propia suite.
     */
    private function knowledgeResolver(Clock $clock): ResolveMailboxKnowledge
    {
        $knowledgeEntries = $this->createMock(MailboxKnowledgeRepositoryInterface::class);
        $knowledgeEntries->method('findForAccount')->willReturn([]);
        $knowledgeEntries->method('findByValue')->willReturn(null);

        $providerRepository = $this->createMock(ProviderRepositoryInterface::class);
        $providerRepository->method('find')->willReturn(null);

        return new ResolveMailboxKnowledge($knowledgeEntries, $providerRepository, $clock);
    }

    private function learnFromMessage(Clock $clock): LearnFromMessage
    {
        $knowledgeEntries = $this->createMock(MailboxKnowledgeRepositoryInterface::class);
        $knowledgeEntries->method('findForAccount')->willReturn([]);
        $knowledgeEntries->method('findByValue')->willReturn(null);

        return new LearnFromMessage($knowledgeEntries, $clock);
    }

    /**
     * Cadena de extracción de texto real, sin OCR: en estos tests los adjuntos
     * son texto plano o PDF con capa de texto.
     */
    private function documentText(): ExtractDocumentText
    {
        return new ExtractDocumentText(
            new DocumentTextExtractorRegistry([new PdfTextExtractor(), new PlainTextExtractor()]),
            new NullOcrEngine(),
        );
    }

    private function message(string $subject = 'Factura OVH 2026-10', ?string $from = 'facturacion@ovh.com'): EmailMessage
    {
        $message = new EmailMessage($this->organizationId, $this->accountId, 'INBOX', 42);

        $message->applyMetadata(
            messageId: '<abc@ovh.com>',
            fromAddress: $from,
            fromName: 'OVH',
            replyTo: null,
            senderDomain: null === $from ? null : mb_substr((string) strrchr($from, '@'), 1),
            toAddresses: ['yo@example.com'],
            subject: $subject,
            receivedAt: new DateTimeImmutable('2026-10-03 08:00:00'),
            sizeBytes: 2048,
            contentType: 'multipart/mixed',
            attachmentNames: ['factura-2026-10.pdf'],
            attachmentTypes: ['application/pdf'],
        );

        return $message;
    }

    private function account(): EmailAccount
    {
        $account = new EmailAccount($this->organizationId, 'yo@ovh.com');
        $account->configureImap(
            host: 'imap.ovh.com',
            encryption: ImapEncryption::SSL,
            port: 993,
            username: 'yo@ovh.com',
        );
        $account->setCredentialsEncrypted('cifrado');

        return $account;
    }

    /**
     * Registra un proveedor conocido en el catálogo y devuelve la entidad: el
     * servicio de prueba tiene que apuntar al **mismo** proveedor, porque la
     * señal de proveedor solo concede puntos con identidad exacta.
     */
    private function knownProvider(string $name, string $domain): Provider
    {
        $provider = new Provider($name, mb_strtolower($name));
        $identity = new ProviderIdentity($provider, ProviderIdentityType::DOMAIN, $domain);

        $this->identities
            ->method('findByTypeAndValue')
            ->willReturnCallback(
                static fn (ProviderIdentityType $type, string $value): ?ProviderIdentity => ProviderIdentityType::DOMAIN === $type && $value === $domain ? $identity : null,
            );

        return $provider;
    }

    private function withBody(string $body): void
    {
        $this->accounts->method('find')->willReturn($this->account());
        $this->cipher->method('decrypt')->willReturn('secreto');
        $this->imap
            ->method('fetchBody')
            ->willReturnCallback(static fn (ImapConnectionConfig $config, string $folder, int $uid): ImapMessageBody => new ImapMessageBody($uid, $body, ''));
    }

    /**
     * @param list<array{name: string, type: string, contents: string}> $attachments
     */
    private function withBodyAndAttachments(string $body, array $attachments): void
    {
        $this->accounts->method('find')->willReturn($this->account());
        $this->cipher->method('decrypt')->willReturn('secreto');
        $this->imap
            ->method('fetchBody')
            ->willReturnCallback(static fn (ImapConnectionConfig $config, string $folder, int $uid): ImapMessageBody => new ImapMessageBody(
                uid: $uid,
                textBody: $body,
                htmlBody: '',
                attachmentNames: array_map(static fn (array $a): string => $a['name'], $attachments),
                attachmentTypes: array_map(static fn (array $a): string => $a['type'], $attachments),
                attachmentContents: array_map(static fn (array $a): string => $a['contents'], $attachments),
            ));
    }

    /** @return list<string> */
    private function states(): array
    {
        return array_map(static fn (MessageProcessingEvent $event): string => $event->getToState()->value, $this->savedEvents);
    }

    public function testAnIrrelevantMessageIsIgnoredWithoutDownloadingItsBody(): void
    {
        $message = $this->message('Novedades de octubre', 'newsletter@tienda.com');

        $this->imap->expects(self::never())->method('fetchBody');

        $result = $this->orchestrator()($message);

        self::assertNull($result);
        self::assertSame(MessageProcessingState::IGNORED, $message->getProcessingState());
        self::assertSame(['ignored'], $this->states());
        self::assertNotNull($message->getProcessedAt());
    }

    public function testATerminalMessageIsNotProcessedAgain(): void
    {
        $message = $this->message();
        $message->setProcessingState(MessageProcessingState::IGNORED);

        $this->imap->expects(self::never())->method('fetchBody');

        self::assertNull($this->orchestrator()($message));
        self::assertSame([], $this->savedEvents);
    }

    public function testTheHappyPathWalksTheWholeStateMachine(): void
    {
        $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Factura nº: FRA-2026-10-0042. Fecha: 2026-10-03. Total 29,90 €. Facturación mensual.');

        $message = $this->message();
        $discovery = $this->orchestrator()($message);

        self::assertSame(['candidate', 'extracted', 'classified', 'discovery'], $this->states());
        self::assertSame(MessageProcessingState::DISCOVERY, $message->getProcessingState());
        self::assertNotNull($discovery);
        self::assertSame(DiscoveryType::NEW_SERVICE, $discovery->getType());
    }

    public function testTheTextOfAnAttachmentIsAnalysedWithTheBody(): void
    {
        $this->knownProvider('OVH', 'ovh.com');

        // El correo no dice nada útil: todo está en el PDF adjunto.
        $this->withBodyAndAttachments(
            'Hola, te adjuntamos la factura del mes.',
            [[
                'name' => 'factura-2026-10.pdf',
                'type' => 'application/pdf',
                'contents' => PdfFixture::withText('Factura nº: FRA-2026-10-0042. Fecha: 2026-10-03. Total 29,90 EUR. Facturacion mensual.'),
            ]],
        );

        $discovery = $this->orchestrator()($this->message());

        self::assertNotNull($discovery);
        self::assertSame(2990, $discovery->getProposedData()['amountMinor']);
        self::assertSame('FRA-2026-10-0042', $discovery->getProposedData()['invoiceNumber']);
    }

    public function testTheAttachmentTextIsPartOfTheContentHash(): void
    {
        $this->knownProvider('OVH', 'ovh.com');

        $this->withBodyAndAttachments(
            'Te adjuntamos la factura.',
            [[
                'name' => 'factura.pdf',
                'type' => 'application/pdf',
                'contents' => PdfFixture::withText('Factura nº: FRA-1. Total 29,90 EUR. Facturacion mensual.'),
            ]],
        );

        $this->orchestrator()($this->message());

        // Mismo cuerpo, adjunto distinto: no puede reutilizarse la extracción.
        self::assertCount(1, $this->cache->all());
        self::assertSame(0, $this->cache->all()[0]->getHitCount());
    }

    public function testTheBodyIsNeverPersistedOnlyAnExcerpt(): void
    {
        $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Factura nº: FRA-1. Total 29,90 €. Facturación mensual. '.str_repeat('relleno ', 200));

        $message = $this->message();
        $this->orchestrator()($message);

        $excerpt = $message->getBodyExcerpt();

        self::assertNotNull($excerpt);
        self::assertLessThanOrEqual(500, mb_strlen($excerpt));
    }

    public function testTheContentHashIsStableForTheSameBody(): void
    {
        self::assertSame(
            ProcessEmailMessage::contentHash("Hola   mundo\n"),
            ProcessEmailMessage::contentHash('hola mundo'),
        );

        self::assertNotSame(
            ProcessEmailMessage::contentHash('hola mundo'),
            ProcessEmailMessage::contentHash('hola mundo!'),
        );
    }

    public function testAMessageWithoutEnoughDataGoesToReview(): void
    {
        $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Total 29,90 €');

        $message = $this->message();
        $result = $this->orchestrator()($message);

        self::assertNull($result);
        self::assertSame(MessageProcessingState::REQUIRES_REVIEW, $message->getProcessingState());
        self::assertSame([], $this->savedDiscoveries);
    }

    public function testAHighConfidenceMatchIsAssociatedWithoutCreatingAService(): void
    {
        $provider = $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Factura nº: FRA-1. Total 29,90 €. Facturación mensual.');

        $service = new Service($this->organizationId, 'OVH', Currency::EUR, BillingPeriod::MONTHLY);
        $service->setProviderId($provider->getId());
        $service->changePrice(Money::of(2990, Currency::EUR), new DateTimeImmutable('2026-01-01'));

        $message = $this->message();
        $result = $this->orchestrator([$service])($message);

        self::assertSame(MessageProcessingState::MATCHED, $message->getProcessingState());
        self::assertNull($result, 'El importe coincide con el vigente: no hay nada que decidir.');
        self::assertSame([], $this->savedDiscoveries);
    }

    public function testAHighConfidenceMatchWithADifferentAmountProposesAPriceChange(): void
    {
        $provider = $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Factura nº: FRA-1. Total 34,90 €. Facturación mensual.');

        $service = new Service($this->organizationId, 'OVH', Currency::EUR, BillingPeriod::MONTHLY);
        $service->setProviderId($provider->getId());
        $service->changePrice(Money::of(2990, Currency::EUR), new DateTimeImmutable('2026-01-01'));

        $message = $this->message();
        $result = $this->orchestrator([$service])($message);

        self::assertNotNull($result);
        self::assertSame(DiscoveryType::PRICE_CHANGE, $result->getType());
        self::assertSame($service->getId(), $result->getMatchedServiceId());
    }

    public function testAnUnknownProviderProposesANewService(): void
    {
        $this->withBody('Factura nº: FRA-1. Total 29,90 €. Facturación mensual.');

        $message = $this->message('Factura', 'facturacion@desconocido.com');
        $result = $this->orchestrator()($message);

        self::assertNotNull($result);
        self::assertSame(DiscoveryType::NEW_SERVICE, $result->getType());
        self::assertSame(MessageProcessingState::DISCOVERY, $message->getProcessingState());
    }

    public function testTheDiscoveryIsAuditedAndLinkedToItsSourceMessage(): void
    {
        $this->withBody('Factura nº: FRA-1. Total 29,90 €. Facturación mensual.');

        $message = $this->message('Factura', 'facturacion@desconocido.com');
        $result = $this->orchestrator()($message);

        self::assertNotNull($result);
        self::assertContains(AuditAction::DISCOVERY_CREATED, $this->audited);
        self::assertSame($message->getId(), $result->getSourceEmailMessageId());
        self::assertCount(1, $this->savedEvidence);
        self::assertSame($result->getId(), $this->savedEvidence[0]->getDiscoveryId());
    }

    /**
     * La misma factura puede llegar por dos buzones de la organización y no
     * debe proponerse dos veces (D-27).
     */
    public function testAnOpenProposalWithTheSameDedupKeyIsReused(): void
    {
        $this->withBody('Factura nº: FRA-1. Total 29,90 €. Facturación mensual.');

        $existing = new Discovery(
            organizationId: $this->organizationId,
            type: DiscoveryType::NEW_SERVICE,
            detectedAt: new DateTimeImmutable('2026-10-04 10:00:00'),
            proposedData: [
                'providerName' => null,
                'amountMinor' => 2990,
                'currency' => 'EUR',
                'billingPeriod' => 'monthly',
            ],
        );

        $this->discoveries->method('findBlockingByDedupKey')->willReturn($existing);

        $message = $this->message('Factura', 'facturacion@desconocido.com');
        $result = $this->orchestrator()($message);

        self::assertSame($existing, $result);
        self::assertSame([], $this->savedDiscoveries, 'No se crea una segunda propuesta.');
        self::assertCount(1, $this->savedEvidence, 'Solo se añade la evidencia.');
    }

    public function testAFailedBodyDownloadDoesNotInvalidateTheMessage(): void
    {
        $this->knownProvider('OVH', 'ovh.com');
        $this->accounts->method('find')->willReturn($this->account());
        $this->cipher->method('decrypt')->willReturn('secreto');
        $this->imap
            ->method('fetchBody')
            ->willThrowException(new \App\Mailbox\Domain\Exception\ImapFetchException('El servidor no responde.'));

        $message = $this->message();
        $result = $this->orchestrator()($message);

        self::assertNull($result);
        self::assertSame(MessageProcessingState::REQUIRES_REVIEW, $message->getProcessingState());
        self::assertSame('El servidor no responde.', $message->getLastError());
    }

    public function testAnUnconfiguredAccountSkipsTheDownload(): void
    {
        $this->knownProvider('OVH', 'ovh.com');
        $this->accounts->method('find')->willReturn(new EmailAccount($this->organizationId, 'yo@ovh.com'));

        $this->imap->expects(self::never())->method('fetchBody');

        $message = $this->message();
        $this->orchestrator()($message);

        self::assertSame(MessageProcessingState::REQUIRES_REVIEW, $message->getProcessingState());
    }

    public function testTheTenantContextIsRestoredAfterProcessing(): void
    {
        $this->withBody('Factura nº: FRA-1. Total 29,90 €. Facturación mensual.');

        $tenantContext = new TenantContext();
        $clock = new Clock(new MockClock(new DateTimeImmutable('2026-10-05 10:00:00')));

        $orchestrator = new ProcessEmailMessage(
            messages: $this->messages,
            accounts: $this->accounts,
            billingScore: new BillingScoreCalculator(BillingScoreWeights::fromArray([]), $this->identities),
            extractor: new DeterministicExtractor(new AmountParser(), new DateParser(), new InvoiceNumberReader(), $this->identities, $clock),
            documentText: $this->documentText(),
            providerResolver: new ProviderResolver($this->identities, $clock),
            providerParser: new ExtractWithProviderParser($this->parsers, new ProviderParserRegistry([]), $clock),
            matcher: new ServiceMatcher($this->services, ServiceMatchWeights::default()),
            discoveries: $this->discoveries,
            documents: $this->documents,
            discoverySettings: new DiscoverySettings(),
            stateMachine: new MessageStateMachine($this->events, $clock),
            imapClient: $this->imap,
            cipher: $this->cipher,
            extractionCache: $this->cache,
            tenantContext: $tenantContext,
            auditLogger: $this->audit,
            clock: $clock,
            aiExtraction: new NullAiExtraction(),
            knowledgeResolver: $this->knowledgeResolver($clock),
            learnFromMessage: $this->learnFromMessage($clock),
        );

        $orchestrator($this->message('Factura', 'facturacion@desconocido.com'));

        self::assertNull($tenantContext->getOrganizationId());
    }

    public function testTheBillingScoreIsPersistedWithItsReasons(): void
    {
        $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Factura nº: FRA-1. Total 29,90 €. Facturación mensual.');

        $message = $this->message();
        $this->orchestrator()($message);

        self::assertGreaterThanOrEqual(40, $message->getBillingScore());
        self::assertNotSame([], $message->getBillingReasons());
    }

    /**
     * El mismo cuerpo no debe analizarse dos veces. Es la garantía de coste del
     * pipeline: un reenvío, un recordatorio o un reintento del worker no pueden
     * volver a pagar por el mismo contenido (D-37).
     */
    public function testTheExtractionIsCachedForTheSameBody(): void
    {
        $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Factura nº: FRA-1. Total 29,90 €. Facturación mensual.');

        $this->orchestrator()($this->message());

        $entries = $this->cache->all();

        self::assertCount(1, $entries);
        self::assertSame(2990, $entries[0]->getDocument()->amountMinor);
        self::assertSame(0, $entries[0]->getHitCount());
        self::assertSame($this->organizationId->toRfc4122(), $entries[0]->getOrganizationId()->toRfc4122());
    }

    public function testASecondMessageWithTheSameBodyReusesTheCachedExtraction(): void
    {
        $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Factura nº: FRA-1. Total 29,90 €. Facturación mensual.');

        $orchestrator = $this->orchestrator();

        $orchestrator($this->message());

        $second = $this->message('RV: Factura OVH 2026-10', 'reenvio@miempresa.com');
        $discovery = $orchestrator($second);

        self::assertCount(1, $this->cache->all());
        self::assertSame(1, $this->cache->all()[0]->getHitCount());
        self::assertNotNull($discovery);

        $extracted = $this->lastEventOf(MessageProcessingState::EXTRACTED);

        self::assertNotNull($extracted);
        self::assertSame('cache', $extracted->getExtractor());
    }

    /**
     * Un acierto de caché devuelve la extracción de otro correo. El importe es
     * del contenido, pero el asunto y el remitente son de **este** mensaje: si
     * se conservaran los del original, la propuesta mostraría al usuario datos
     * de un correo que no ha visto.
     */
    public function testACacheHitRefreshesTheSenderAndSubject(): void
    {
        $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Factura nº: FRA-1. Total 29,90 €. Facturación mensual.');

        $orchestrator = $this->orchestrator();

        $orchestrator($this->message());

        $second = $this->message('RV: Factura OVH 2026-10', 'reenvio@miempresa.com');
        $discovery = $orchestrator($second);

        self::assertNotNull($discovery);

        $proposed = $discovery->getProposedData();

        self::assertSame('reenvio@miempresa.com', $proposed['sender']);
        self::assertSame('miempresa.com', $proposed['senderDomain']);
        self::assertSame('RV: Factura OVH 2026-10', $proposed['subject']);
        self::assertSame(2990, $proposed['amountMinor']);
    }

    /**
     * `contentHash('')` es un hash perfectamente válido. Sin la guarda, todos
     * los correos sin cuerpo compartirían una única entrada y se reutilizarían
     * extracciones entre mensajes que no tienen nada que ver.
     */
    public function testABlankBodyIsNeverCached(): void
    {
        $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('');

        $this->orchestrator()($this->message());

        self::assertSame([], $this->cache->all());
    }

    public function testTheCacheIsNotWrittenTwiceForTheSameBody(): void
    {
        $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Factura nº: FRA-1. Total 29,90 €. Facturación mensual.');

        $orchestrator = $this->orchestrator();

        $orchestrator($this->message());
        $orchestrator($this->message('Otra factura', 'facturacion@ovh.com'));

        self::assertCount(1, $this->cache->all());
    }

    /**
     * Reprocesar un mensaje ya resuelto no puede costar nada: ni una descarga
     * de cuerpo, ni una extracción, ni un descubrimiento duplicado.
     */
    public function testReprocessingATerminalMessageCostsNothing(): void
    {
        $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Factura nº: FRA-1. Total 29,90 €. Facturación mensual.');

        $orchestrator = $this->orchestrator();
        $message = $this->message();

        $orchestrator($message);

        $discoveriesAfterFirstPass = count($this->savedDiscoveries);
        $eventsAfterFirstPass = count($this->savedEvents);

        self::assertNull($orchestrator($message));
        self::assertCount($discoveriesAfterFirstPass, $this->savedDiscoveries);
        self::assertCount($eventsAfterFirstPass, $this->savedEvents);
        self::assertCount(1, $this->cache->all());
    }

    // ── Escalera de IA: cuándo se llama y cuándo no ────────────────────────

    public function testTheAiEsCalledWhenTheDeterministicPathIsNotEnough(): void
    {
        $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Total 29,90 €');

        $ai = new RecordingAiExtraction();
        $this->orchestrator([], $ai)($this->message());

        self::assertSame(1, $ai->callCount());
    }

    public function testTheAiIsNotCalledWhenTheDocumentIsAlreadyActionable(): void
    {
        // El caso ya está resuelto: llamarla sería pagar por no obtener nada.
        $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Factura nº: FRA-1. Total 29,90 €. Facturación mensual.');

        $ai = new RecordingAiExtraction();
        $this->orchestrator([], $ai)($this->message());

        self::assertFalse($ai->wasCalled());
    }

    public function testTheAiIsNotCalledOnACacheHit(): void
    {
        // Un acierto de caché no se reinterpreta: el contenido ya se analysó
        // una vez, y volver a llamarla sería pagar dos veces por lo mismo.
        $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Factura nº: FRA-1. Total 29,90 €. Facturación mensual.');

        $ai = new RecordingAiExtraction();
        $orchestrator = $this->orchestrator([], $ai);

        $orchestrator($this->message());
        $orchestrator($this->message('RV: Factura OVH 2026-10', 'reenvio@miempresa.com'));

        self::assertSame(0, $ai->callCount());
    }

    public function testTheDocumentOfTheAiIsAdoptedWhenItIsActionable(): void
    {
        // El cuerpo no trae periodicidad: la vía determinista no puede
        // resolverlo, y el modelo sí.
        $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Factura nº: FRA-1. Total 29,90 €.');

        $aiDocument = new ExtractedDocument(
            tier: ExtractionTier::AI_CHEAP,
            confidence: 0.9,
            amountMinor: 3490,
            currency: 'EUR',
            billingPeriod: BillingPeriod::MONTHLY,
            providerName: 'OVH',
        );

        $ai = new RecordingAiExtraction($aiDocument);
        $result = $this->orchestrator([], $ai)($this->message('Factura', 'facturacion@desconocido.com'));

        self::assertNotNull($result);
        self::assertSame(3490, $result->getProposedData()['amountMinor']);

        $extracted = $this->lastEventOf(MessageProcessingState::EXTRACTED);

        self::assertNotNull($extracted);
        self::assertSame('ai:ai_cheap', $extracted->getExtractor());
        self::assertSame(ExtractionTier::AI_CHEAP, $extracted->getTier());
    }

    public function testTheDocumentOfTheAiIsIgnoredWhenItIsNotActionable(): void
    {
        // Un documento que la IA no ha podido completar no sustituye al
        // determinista: el pipeline sigue con lo que tiene.
        $this->knownProvider('OVH', 'ovh.com');
        $this->withBody('Factura nº: FRA-1. Total 29,90 €.');

        $aiDocument = new ExtractedDocument(
            tier: ExtractionTier::AI_CHEAP,
            confidence: 0.5,
            amountMinor: 2990,
        );

        $ai = new RecordingAiExtraction($aiDocument);
        $message = $this->message('Factura', 'facturacion@desconocido.com');
        $result = $this->orchestrator([], $ai)($message);

        self::assertNull($result, 'El documento no es accionable: no hay nada que proponer.');
        self::assertSame(MessageProcessingState::REQUIRES_REVIEW, $message->getProcessingState());

        $extracted = $this->lastEventOf(MessageProcessingState::EXTRACTED);

        self::assertNotNull($extracted);
        self::assertNotSame('ai:ai_cheap', $extracted->getExtractor());
    }

    private function lastEventOf(MessageProcessingState $state): ?MessageProcessingEvent
    {
        $found = null;

        foreach ($this->savedEvents as $event) {
            if ($event->getToState() === $state) {
                $found = $event;
            }
        }

        return $found;
    }
}
