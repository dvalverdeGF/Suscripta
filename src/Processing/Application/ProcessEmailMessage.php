<?php

declare(strict_types=1);

namespace App\Processing\Application;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Entity\DiscoveryEvidence;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Documents\Domain\Enum\DocumentType;
use App\Mailbox\Application\CredentialCipherInterface;
use App\Mailbox\Application\Imap\ImapClientInterface;
use App\Mailbox\Application\Imap\ImapConnectionConfig;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Enum\MessageClassification;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Exception\ImapFetchException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Processing\Application\Extraction\DeterministicExtractor;
use App\Processing\Application\Matching\ServiceMatcher;
use App\Processing\Application\Provider\ExtractWithProviderParser;
use App\Processing\Application\Text\ExtractDocumentText;
use App\Processing\Domain\Billing\BillingClassifierInterface;
use App\Processing\Domain\Dto\ExtractedDocument;
use App\Processing\Domain\Provider\ProviderResolverInterface;
use App\Processing\Domain\Dto\ServiceMatchResult;
use App\Processing\Domain\Entity\ExtractionCache;
use App\Processing\Domain\Repository\ExtractionCacheRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;

use function hash;
use function implode;
use function mb_strtolower;
use function mb_substr;
use function preg_replace;
use function round;
use function sprintf;

use Symfony\Component\Uid\Uuid;
use Throwable;

use function trim;

/**
 * Orquestador del pipeline para un mensaje (ARCHITECTURE.md §13.1).
 *
 * Recorre los niveles 2 a 4 y decide qué hacer con el mensaje. Es el único
 * punto donde se encadenan filtro, extracción, emparejamiento y propuesta, y
 * por eso es también el único sitio donde se decide **cuándo no se llama a
 * IA**: en la Fase 3 no se llama nunca, y el diseño deja el hueco preparado
 * para los niveles 5 y 6 sin tocar el dominio.
 *
 * Dos invariantes que no se negocian:
 *
 * - **Nunca se crea un `Service` automáticamente** (D-35). Como mucho se crea
 *   un `Discovery` en estado `pending` y espera al usuario.
 * - **El cuerpo del correo no se persiste** (D-10). Se descarga, se analiza y
 *   se descarta; solo sobrevive un extracto corto cuando hay algo que revisar.
 */
final readonly class ProcessEmailMessage
{
    /**
     * Longitud del extracto que se conserva para que el usuario pueda revisar
     * una propuesta. Es un extracto, no el correo (D-10).
     */
    private const int EXCERPT_LENGTH = 500;

    /**
     * Nombre con el que se registra una extracción servida desde la caché. Que
     * aparezca en el log de transiciones es lo que permite medir cuánto ahorra
     * la caché sin instrumentar nada más.
     */
    private const string CACHE_EXTRACTOR = 'cache';

    public function __construct(
        private EmailMessageRepositoryInterface $messages,
        private EmailAccountRepositoryInterface $accounts,
        private BillingClassifierInterface $billingScore,
        private DeterministicExtractor $extractor,
        private ExtractDocumentText $documentText,
        private ProviderResolverInterface $providerResolver,
        private ExtractWithProviderParser $providerParser,
        private ServiceMatcher $matcher,
        private DiscoveryRepositoryInterface $discoveries,
        private MessageStateMachine $stateMachine,
        private ImapClientInterface $imapClient,
        private CredentialCipherInterface $cipher,
        private ExtractionCacheRepositoryInterface $extractionCache,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
    ) {
    }

    /**
     * Procesa un mensaje ya almacenado y devuelve la propuesta creada, si la
     * hay.
     *
     * Es idempotente: un mensaje en estado terminal no se vuelve a procesar, y
     * un mensaje que ya generó una propuesta abierta no genera otra (D-37).
     *
     * @throws ImapConnectionException
     * @throws ImapFetchException
     */
    public function __invoke(EmailMessage $message, ?Uuid $actorUserId = null, ?string $body = null): ?Discovery
    {
        return $this->tenantContext->runAs(
            $message->getOrganizationId(),
            fn (): ?Discovery => $this->process($message, $actorUserId, $body),
        );
    }

    private function process(EmailMessage $message, ?Uuid $actorUserId, ?string $body = null): ?Discovery
    {
        if ($message->getProcessingState()->isTerminal()) {
            return null;
        }

        $message->beginProcessingAttempt();

        $header = ImapMessageHeader::fromStoredMessage($message);

        // ── Nivel 2: filtro determinista, solo con metadatos ────────────────
        $score = $this->billingScore->score($header);
        $message->applyBillingScore($score->score, $score->reasonsAsArray());

        if (!$score->isCandidate()) {
            $this->stateMachine->transition(
                $message,
                MessageProcessingState::IGNORED,
                $score->explain(),
                data: ['billingScore' => $score->score],
            );
            $message->markProcessed($this->clock->now());
            $this->messages->save($message);

            return null;
        }

        $this->stateMachine->transition(
            $message,
            MessageProcessingState::CANDIDATE,
            $score->explain(),
            data: ['billingScore' => $score->score],
        );

        // ── Nivel 3: descarga perezosa del cuerpo y extracción determinista ─
        $content = $this->fetchContent($message, $body);
        $body = $content['body'];

        // El texto de los adjuntos entra en el mismo saco que el cuerpo: una
        // factura en PDF trae el importe y el número, y el correo que la
        // acompaña casi nunca. Se analiza todo junto para que el extractor y el
        // parser vean el documento completo y no solo el saludo.
        $haystack = $this->haystack($body, $content['attachments']);
        $contentHash = self::contentHash($haystack);
        $message->setContentHash($contentHash);

        $rescored = $this->billingScore->score($header, $haystack);
        $message->applyBillingScore($rescored->score, $rescored->reasonsAsArray());

        // ── Nivel 4: identificación del proveedor (solo metadatos) ──────────
        // Se resuelve antes de extraer porque el nombre del proveedor forma
        // parte del resultado y porque un proveedor conocido habilita su parser.
        $providerMatch = $this->providerResolver->resolve($header);

        // La caché se consulta antes de extraer: un reenvío, un recordatorio o
        // un reintento del mismo correo no deben pagar dos veces por el mismo
        // análisis (D-37).
        $cached = $this->cachedExtraction($header, $haystack, $contentHash);

        if (null !== $cached) {
            $document = $cached;
            $extractorName = self::CACHE_EXTRACTOR;
        } else {
            // ── Nivel 3: extracción determinista ────────────────────────────
            $document = $this->extractor->extract($header, $haystack, $providerMatch);
            $extractorName = $this->extractor->name();

            // ── Nivel 4: parser del proveedor conocido ──────────────────────
            // Se superpone al resultado genérico en lugar de sustituirlo: el
            // parser conoce la plantilla y el extractor genérico mira el
            // documento entero, así que cada uno tapa lo que el otro no ve.
            $parsed = ($this->providerParser)($header, $providerMatch, $haystack);

            if (null !== $parsed) {
                $document = $document->withProviderParse(
                    $parsed,
                    $providerMatch->displayName() ?? $parsed->serviceName ?? '',
                );
                $extractorName = $parsed->parserKey;
            }

            $this->remember($message, $haystack, $contentHash, $document);
        }

        $message->applyClassification(
            $this->classificationOf($document),
            (int) round($document->confidence * 100),
        );
        $message->recordExtraction($document->tier, $extractorName);

        $this->stateMachine->transition(
            $message,
            MessageProcessingState::EXTRACTED,
            sprintf('Extracción determinista con confianza %.2f.', $document->confidence),
            extractor: $extractorName,
            tier: $document->tier,
            data: $document->toArray(),
        );

        if (!$document->isActionable()) {
            $message->setBodyExcerpt($this->excerpt($body));
            $this->stateMachine->transition(
                $message,
                MessageProcessingState::REQUIRES_REVIEW,
                'Faltan datos para calcular un coste recurrente.',
                extractor: $extractorName,
                tier: $document->tier,
            );
            $message->markProcessed($this->clock->now());
            $this->messages->save($message);

            return null;
        }

        $this->stateMachine->transition(
            $message,
            MessageProcessingState::CLASSIFIED,
            'Documento de facturación reconocido.',
            extractor: $extractorName,
            tier: $document->tier,
        );

        // ── Nivel 4: emparejamiento con el inventario de servicios ─────────
        $match = $this->matcher->match($document);

        if ($match->isHighConfidence()) {
            return $this->associate($message, $document, $match, $extractorName, $actorUserId);
        }

        return $this->propose($message, $document, $match, $body, $extractorName, $actorUserId);
    }

    /**
     * Coincidencia alta: el documento pertenece a un servicio que ya existe.
     *
     * No se toca el precio automáticamente. Un cambio de importe es una
     * decisión de negocio del usuario, así que se propone como
     * `price_change`; si el importe coincide con el vigente, no hay nada que
     * decidir y el mensaje queda asociado sin más.
     */
    private function associate(
        EmailMessage $message,
        ExtractedDocument $document,
        ServiceMatchResult $match,
        string $extractorName,
        ?Uuid $actorUserId,
    ): ?Discovery {
        $this->stateMachine->transition(
            $message,
            MessageProcessingState::MATCHED,
            $match->explain(),
            extractor: $extractorName,
            tier: $document->tier,
            data: ['matchScore' => $match->score, 'serviceId' => $match->serviceId?->toRfc4122()],
        );

        $message->markProcessed($this->clock->now());
        $this->messages->save($message);

        if (!$this->amountDiffers($document, $match)) {
            return null;
        }

        return $this->createDiscovery(
            $message,
            $document,
            $match,
            DiscoveryType::PRICE_CHANGE,
            $actorUserId,
        );
    }

    /**
     * Coincidencia media o baja: hay algo que proponer, pero lo decide el
     * usuario.
     */
    private function propose(
        EmailMessage $message,
        ExtractedDocument $document,
        ServiceMatchResult $match,
        string $body,
        string $extractorName,
        ?Uuid $actorUserId,
    ): Discovery {
        $type = $match->isMediumConfidence() ? DiscoveryType::PRICE_CHANGE : DiscoveryType::NEW_SERVICE;

        $this->stateMachine->transition(
            $message,
            MessageProcessingState::DISCOVERY,
            $match->explain(),
            extractor: $extractorName,
            tier: $document->tier,
            data: ['matchScore' => $match->score, 'type' => $type->value],
        );

        $message->setBodyExcerpt($this->excerpt($body));
        $message->markProcessed($this->clock->now());
        $this->messages->save($message);

        return $this->createDiscovery($message, $document, $match, $type, $actorUserId);
    }

    private function createDiscovery(
        EmailMessage $message,
        ExtractedDocument $document,
        ServiceMatchResult $match,
        DiscoveryType $type,
        ?Uuid $actorUserId,
    ): Discovery {
        $proposedData = $document->toArray();
        $dedupKey = Discovery::buildDedupKey($type, $proposedData);

        // Deduplicación entre cuentas (D-27): la misma factura puede llegar por
        // dos buzones de la organización y no debe proponerse dos veces.
        $existing = $this->discoveries->findOpenByDedupKey($dedupKey);

        if (null !== $existing) {
            $this->discoveries->saveEvidence(new DiscoveryEvidence(
                discoveryId: $existing->getId(),
                createdAt: $this->clock->now(),
                emailMessageId: $message->getId(),
                weight: $match->score,
            ));

            return $existing;
        }

        $discovery = new Discovery(
            organizationId: $message->getOrganizationId(),
            type: $type,
            detectedAt: $this->clock->now(),
            proposedData: $proposedData,
        );

        $discovery->applyMatch($match->score, $match->reasonsAsArray(), $match->serviceId);
        $discovery->applyConfidence((int) round($document->confidence * 100));
        $discovery->recordExtraction($document->tier, $document->tier->usedAi());
        $discovery->attachSourceMessage($message->getId());

        $this->discoveries->save($discovery, false);
        $this->discoveries->saveEvidence(new DiscoveryEvidence(
            discoveryId: $discovery->getId(),
            createdAt: $this->clock->now(),
            emailMessageId: $message->getId(),
            weight: $match->score,
        ));

        $this->auditLogger->log(
            action: AuditAction::DISCOVERY_CREATED,
            targetType: 'discovery',
            targetId: $discovery->getId()->toRfc4122(),
            metadata: [
                'type' => $type->value,
                'provider' => $document->providerName,
                'matchScore' => $match->score,
                'tier' => $document->tier->value,
            ],
            actorUserId: $actorUserId,
        );

        return $discovery;
    }

    /**
     * Descarga el cuerpo del mensaje. Es el único punto del pipeline que toca
     * el buzón después de la sincronización, y solo ocurre para los mensajes
     * que han superado el filtro determinista (D-38).
     */
    /**
     * Devuelve la extracción ya calculada para este contenido, si existe.
     *
     * Un cuerpo vacío no identifica nada: todos los mensajes sin cuerpo
     * compartirían el mismo hash y se reutilizarían extracciones entre correos
     * que no tienen nada que ver. Por eso la caché solo se consulta cuando hay
     * texto real.
     */
    private function cachedExtraction(ImapMessageHeader $header, string $body, string $contentHash): ?ExtractedDocument
    {
        if ('' === trim($body)) {
            return null;
        }

        $entry = $this->extractionCache->findForContentHash($contentHash);

        if (null === $entry) {
            return null;
        }

        $entry->recordHit($this->clock->now());
        $this->extractionCache->save($entry);

        // El importe y las fechas son del contenido, pero el asunto y el
        // remitente son de este mensaje: se refrescan para que la propuesta no
        // muestre datos de un correo que el usuario no ha visto.
        return $entry->getDocument()->withSenderContext(
            $header->fromAddress,
            $header->senderDomain(),
            $header->subject,
        );
    }

    private function remember(EmailMessage $message, string $body, string $contentHash, ExtractedDocument $document): void
    {
        // Un cuerpo vacío no identifica nada: `contentHash('')` es un hash
        // perfectamente válido, así que sin esta guarda todos los correos sin
        // cuerpo compartirían una única entrada y se reutilizarían extracciones
        // entre mensajes que no tienen nada que ver.
        if ('' === trim($body)) {
            return;
        }

        if (null !== $this->extractionCache->findForContentHash($contentHash)) {
            return;
        }

        $this->extractionCache->save(new ExtractionCache(
            organizationId: $message->getOrganizationId(),
            contentHash: $contentHash,
            document: $document,
            createdAt: $this->clock->now(),
        ));
    }

    /**
     * Descarga perezosa del cuerpo y de los adjuntos (D-38).
     *
     * Devuelve el cuerpo del correo y, ya convertidos a texto, sus adjuntos.
     * Un mensaje reenviado no está en ningún buzón: su cuerpo llegó con la
     * petición y ya se ha consumido, así que no hay nada que descargar.
     *
     * @return array{body: string, attachments: list<array{name: string, type: string, contents: string}>}
     */
    private function fetchContent(EmailMessage $message, ?string $body): array
    {
        if (null !== $body) {
            return ['body' => $body, 'attachments' => []];
        }

        if (null === $message->getUid() || !$message->getSource()->requiresMailboxAccess()) {
            return ['body' => '', 'attachments' => []];
        }

        $account = $this->accounts->find($message->getEmailAccountId());

        if (null === $account || !$account->isConfigured()) {
            return ['body' => '', 'attachments' => []];
        }

        try {
            $body = $this->imapClient->fetchBody(
                new ImapConnectionConfig(
                    host: (string) $account->getImapHost(),
                    port: (int) $account->getImapPort(),
                    encryption: $account->getImapEncryption() ?? ImapEncryption::SSL,
                    username: (string) $account->getImapUsername(),
                    password: $this->cipher->decrypt((string) $account->getCredentialsEncrypted()),
                ),
                $message->getFolder(),
                $message->getUid(),
            );
        } catch (Throwable $e) {
            // Un fallo de red no debe invalidar el mensaje: se sigue con los
            // metadatos, que ya han demostrado ser suficientes para puntuar.
            $message->recordFailure($e->getMessage());

            return ['body' => '', 'attachments' => []];
        }

        return [
            'body' => trim($body->textBody) !== '' ? $body->textBody : $body->htmlBody,
            'attachments' => $body->attachments(),
        ];
    }

    /**
     * Texto que se analiza: el cuerpo del correo más el texto de sus adjuntos.
     *
     * Los adjuntos pasan por la cadena de extracción de texto (nivel 3), que
     * intenta primero la capa de texto del documento y solo recurre a OCR
     * cuando no hay ninguna. Todo ocurre dentro de nuestra infraestructura: en
     * este punto no sale nada hacia fuera (D-24).
     *
     * @param list<array{name: string, type: string, contents: string}> $attachments
     */
    private function haystack(string $body, array $attachments): string
    {
        $parts = [trim($body)];

        foreach ($attachments as $attachment) {
            $text = ($this->documentText)(
                $attachment['contents'],
                $attachment['type'],
                $attachment['name'],
            );

            if (!$text->isEmpty()) {
                $parts[] = $text->text;
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * Hash del contenido normalizado. Es la clave de caché de extracción: dos
     * correos con el mismo cuerpo (reenvíos, recordatorios) no deben costar dos
     * análisis (D-37).
     */
    public static function contentHash(string $body): string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($body))) ?? '';

        return hash('sha256', $normalized);
    }

    private function excerpt(string $body): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $body) ?? ''), 0, self::EXCERPT_LENGTH);
    }

    private function classificationOf(ExtractedDocument $document): MessageClassification
    {
        return match ($document->documentType) {
            DocumentType::INVOICE => MessageClassification::INVOICE,
            DocumentType::RECEIPT => MessageClassification::RECEIPT,
            DocumentType::CONTRACT, DocumentType::OTHER => MessageClassification::OTHER,
        };
    }

    private function amountDiffers(ExtractedDocument $document, ServiceMatchResult $match): bool
    {
        if (null === $match->serviceId || null === $document->amountMinor) {
            return false;
        }

        foreach ($match->reasons as $reason) {
            if ('Importe compatible' === $reason['signal']) {
                return false;
            }
        }

        return true;
    }
}
