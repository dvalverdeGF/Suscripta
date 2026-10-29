<?php

declare(strict_types=1);

namespace App\Processing\Application;

use App\Ai\Application\AiExtractionInterface;
use App\Discovery\Application\DiscoverySettings;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Entity\DiscoveryEvidence;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Documents\Domain\Enum\DocumentType;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Mailbox\Application\Imap\ImapClientInterface;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Mailbox\Application\Imap\ResolveImapConnection;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Mailbox\Domain\Enum\MessageClassification;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Exception\ImapFetchException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Processing\Application\Extraction\DeterministicExtractor;
use App\Processing\Application\Knowledge\Dto\KnowledgeObservation;
use App\Processing\Application\Knowledge\LearnFromMessage;
use App\Processing\Application\Knowledge\ResolveMailboxKnowledge;
use App\Processing\Application\Provider\ExtractWithProviderParser;
use App\Processing\Application\Text\ExtractDocumentText;
use App\Processing\Domain\Billing\BillingClassifierInterface;
use App\Processing\Domain\Dto\ExtractedDocument;
use App\Processing\Domain\Dto\MailboxKnowledge;
use App\Processing\Domain\Dto\ServiceMatchResult;
use App\Processing\Domain\Entity\ExtractionCache;
use App\Processing\Domain\Enum\MailboxKnowledgeSource;
use App\Processing\Domain\Matching\ServiceMatcherInterface;
use App\Processing\Domain\Provider\ProviderMatch;
use App\Processing\Domain\Provider\ProviderResolverInterface;
use App\Processing\Domain\Repository\ExtractionCacheRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Webhooks\Application\WebhookNotifier;
use App\Webhooks\Domain\Enum\WebhookEvent;

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
        private ServiceMatcherInterface $matcher,
        private DiscoveryRepositoryInterface $discoveries,
        private DocumentRepositoryInterface $documents,
        private DiscoverySettings $discoverySettings,
        private MessageStateMachine $stateMachine,
        private ImapClientInterface $imapClient,
        private ResolveImapConnection $resolveConnection,
        private ExtractionCacheRepositoryInterface $extractionCache,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
        private AiExtractionInterface $aiExtraction,
        private ResolveMailboxKnowledge $knowledgeResolver,
        private LearnFromMessage $learnFromMessage,
        /**
         * Opcional para que las pruebas unitarias que no ejercitan webhooks no
         * tengan que montar la cola. En la aplicación siempre llega inyectado.
         */
        private ?WebhookNotifier $webhooks = null,
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

        // ── Conocimiento aprendido del buzón (Fase 13) ─────────────────────
        // Se resuelve antes de todo lo demás, y a propósito: lo aprendido de
        // este buzón es lo único que puede hacer que el mensaje **no se
        // procese** (remitente ignorado) o que se resuelva sin pagar IA.
        $knowledge = ($this->knowledgeResolver)($message->getEmailAccountId(), $header);

        if ($knowledge->isIgnored()) {
            $this->stateMachine->transition(
                $message,
                MessageProcessingState::IGNORED,
                'El remitente está en la lista de ignorados de este buzón.',
                data: ['knowledge' => 'ignored_sender'],
            );
            $message->markProcessed($this->clock->now());
            $this->messages->save($message);

            return null;
        }

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
        //
        // **El conocimiento del buzón manda sobre el catálogo global**: lo que
        // el usuario ha corregido en *este* buzón es más cierto que lo que dice
        // el catálogo, que es compartido por todos (D-33).
        $providerMatch = $this->resolveProvider($header, $knowledge);

        // La caché se consulta antes de extraer: un reenvío, un recordatorio o
        // un reintento del mismo correo no deben pagar dos veces por el mismo
        // análisis (D-37).
        $cached = $this->cachedExtraction($header, $haystack, $contentHash);

        if (null !== $cached) {
            $document = $cached;
            $extractorName = self::CACHE_EXTRACTOR;
        } else {
            // ── Nivel 3: extracción determinista ────────────────────────────
            $document = $this->extractor->extract($header, $haystack, $providerMatch, $knowledge);
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
        }

        // ── Nivel 5-6: escalera de IA ─────────────────────────────────────
        // Solo entra si la vía determinista no ha resuelto el caso. Un
        // documento que ya tiene importe, proveedor y periodicidad no se
        // manda a un modelo: se pagaría por no obtener nada. Un acierto de
        // caché tampoco se reinterpreta: el contenido ya se analysó una vez y
        // volver a llamaría a la IA sería pagar dos veces por lo mismo (D-37).
        if (null === $cached && !$document->isActionable()) {
            $outcome = $this->aiExtraction->enrich($document, $haystack, $header);

            // El presupuesto se ha agotado y el modo es `HARD`: no se puede
            // llamar a ningún modelo. El correo **no está mal**, está
            // esperando, así que se aplaza en vez de mandarlo a revisión
            // manual (D-56, D-57). Volverá al pipeline cuando haya
            // presupuesto.
            if ($outcome->defersMessage()) {
                $this->stateMachine->transition(
                    $message,
                    MessageProcessingState::DEFERRED,
                    'Se aplaza el análisis: el presupuesto de IA está agotado.',
                    data: ['skip_reason' => $outcome->reason->value],
                );

                // No se marca como procesado: el correo no ha terminado, solo
                // está en espera. Si se marcara, el tiempo medio de
                // procesamiento mediría los días que ha estado esperando
                // presupuesto en vez del trabajo que ha hecho (Fase 14).
                $this->messages->save($message);

                return null;
            }

            if (null !== $outcome->document && $outcome->document->isActionable()) {
                $document = $outcome->document;
                $extractorName = 'ai:'.$document->tier->value;
            }
        }

        // ── Fase 13: aprender del buzón ────────────────────────────────────
        // Lo que el pipeline acaba de resolver se guarda como conocimiento del
        // buzón, para que el próximo correo del mismo remitente se resuelva sin
        // pagar IA. Solo se aprende de extracciones con garantías: un
        // proveedor identificado de verdad y un tipo de documento conocido.
        $this->learn($message, $header, $providerMatch, $document);

        // La caché guarda el mejor documento conocido: si la IA ha completado
        // uno que el extractor determinista dejó a medias, se guarda ese.
        if (null === $cached) {
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
        // El proveedor resuelto se pasa al emparejador porque la señal de
        // proveedor es la más pesada del modelo y solo puede concederse cuando
        // hay **identidad** en el catálogo. Un proveedor provisional (deducido
        // por nombre) no aporta ninguno: un nombre adivinado no prueba nada.
        $match = $this->matcher->match($document, $providerMatch->providerId());

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

        // Un cambio de plan manda sobre un cambio de precio: cuando el plan
        // cambia, el importe suele cambiar con él, y proponer solo el importe
        // dejaría el servicio con el plan viejo y el precio nuevo. La
        // propuesta de cambio de plan lleva los dos datos, así que el usuario
        // decide los dos de una vez.
        if (null !== $this->planChange($document, $match)) {
            return $this->createDiscovery(
                $message,
                $document,
                $match,
                DiscoveryType::PLAN_CHANGE,
                $actorUserId,
            );
        }

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

        // El servicio al que pertenece la propuesta viaja en los datos
        // propuestos: es lo que permite que la interfaz lo enlace y lo que
        // forma parte de la clave de deduplicación de los cambios.
        if (null !== $match->serviceId) {
            $proposedData['serviceId'] = $match->serviceId->toRfc4122();
        }

        if (DiscoveryType::PLAN_CHANGE === $type) {
            $proposedData['previousPlan'] = $match->servicePlanName;
        }

        $dedupKey = Discovery::buildDedupKey($type, $proposedData);
        $documentId = $this->documentIdFor($message->getId());

        // Deduplicación entre cuentas (D-27): la misma factura puede llegar por
        // dos buzones de la organización y no debe proponerse dos veces.
        //
        // También bloquea lo que el usuario ya descartó (D-45): una propuesta
        // ignorada no vuelve a preguntar durante la ventana de supresión.
        $existing = $this->discoveries->findBlockingByDedupKey(
            $dedupKey,
            $this->discoverySettings->ignoredSince($this->clock->now()),
        );

        if (null !== $existing) {
            $this->discoveries->saveEvidence(new DiscoveryEvidence(
                discoveryId: $existing->getId(),
                createdAt: $this->clock->now(),
                emailMessageId: $message->getId(),
                documentId: $documentId,
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
            documentId: $documentId,
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

        $this->webhooks?->notify($message->getOrganizationId(), WebhookEvent::PROPOSAL_CREATED, [
            'discoveryId' => $discovery->getId()->toRfc4122(),
            'type' => $type->value,
            'providerName' => $document->providerName,
            'planName' => $document->plan,
            'amountMinor' => $document->amountMinor,
            'currency' => $document->currency,
            'matchScore' => $match->score,
            'confidence' => $discovery->getConfidenceScore(),
        ]);

        return $discovery;
    }

    /**
     * Documento del que procede la propuesta, si el correo tiene uno guardado.
     *
     * La evidencia sirve para que el usuario pueda ir al documento original y
     * comprobar por sí mismo lo que el sistema ha deducido. Un correo sin
     * documento guardado (porque el pipeline no guarda el cuerpo, D-10) sólo
     * puede aportar la referencia al mensaje.
     */
    private function documentIdFor(Uuid $emailMessageId): ?Uuid
    {
        $documents = $this->documents->findForEmailMessage($emailMessageId);

        if ([] === $documents) {
            return null;
        }

        return $documents[0]->getId();
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
                ($this->resolveConnection)($account),
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

    /**
     * Identifica el proveedor mezclando el conocimiento del buzón con el
     * catálogo global.
     *
     * Lo aprendido en *este* buzón tiene prioridad: refleja correcciones del
     * usuario sobre su correo real, mientras que el catálogo es compartido
     * por todos los tenants (D-51).
     */
    private function resolveProvider(ImapMessageHeader $header, MailboxKnowledge $knowledge): ProviderMatch
    {
        if ($knowledge->hasProvider()) {
            return new ProviderMatch(
                provider: $knowledge->provider,
                provisionalName: null,
                matchedBy: 'mailbox_knowledge',
                confidence: max(70, $knowledge->confidence()),
            );
        }

        return $this->providerResolver->resolve($header);
    }

    /**
     * Traduce el resultado de la extracción a una observación y la guarda.
     *
     * Solo se aprende de resultados con garantías (D-52):
     *  - el proveedor está identificado de verdad (no un nombre provisional);
     *  - el tipo de documento no es "otro";
     *  - la extracción no fue una escalada desesperada a IA avanzada.
     *
     * La confianza de la observación refleja el nivel de la extracción: un
     * parser de proveedor conocido es más fiable que una IA económica.
     */
    private function learn(
        EmailMessage $message,
        ImapMessageHeader $header,
        ProviderMatch $providerMatch,
        ExtractedDocument $document,
    ): void {
        if (!$providerMatch->isKnown()) {
            return;
        }

        if (DocumentType::OTHER === $document->documentType) {
            return;
        }

        if (ExtractionTier::AI_ADVANCED === $document->tier) {
            return;
        }

        $observation = new KnowledgeObservation(
            emailAccountId: $message->getEmailAccountId(),
            organizationId: $message->getOrganizationId(),
            sender: $message->getFromAddress(),
            senderDomain: $header->senderDomain(),
            subject: $header->subject,
            attachmentNames: $header->attachmentNames,
            providerId: $providerMatch->providerId(),
            documentType: $document->documentType,
            billingPeriod: $document->billingPeriod,
            source: MailboxKnowledgeSource::OBSERVED,
            confidence: ExtractionTier::AI_CHEAP === $document->tier ? 60 : 80,
        );

        ($this->learnFromMessage)($observation);
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

    /**
     * ¿El documento trae un plan distinto del que tiene el servicio?
     *
     * Solo cuenta como cambio si **los dos** lo dicen: un servicio sin plan
     * registrado al que la factura le dice el plan no está cambiando de
     * plan, estáyaciendo más completo. Y si la factura no menciona plan, no
     * hay nada que comparar.
     */
    private function planChange(ExtractedDocument $document, ServiceMatchResult $match): ?string
    {
        $documentPlan = $document->plan;
        $servicePlan = $match->servicePlanName;

        if (null === $documentPlan || null === $servicePlan) {
            return null;
        }

        $from = mb_strtolower(trim($servicePlan));
        $to = mb_strtolower(trim($documentPlan));

        if ('' === $from || '' === $to || $from === $to) {
            return null;
        }

        return trim($documentPlan);
    }
}
