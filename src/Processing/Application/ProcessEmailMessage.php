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
use App\Processing\Application\Billing\BillingScoreCalculator;
use App\Processing\Application\Extraction\DeterministicExtractor;
use App\Processing\Application\Matching\ServiceMatcher;
use App\Processing\Domain\Dto\ExtractedDocument;
use App\Processing\Domain\Dto\ServiceMatchResult;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;

use function hash;
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

    public function __construct(
        private EmailMessageRepositoryInterface $messages,
        private EmailAccountRepositoryInterface $accounts,
        private BillingScoreCalculator $billingScore,
        private DeterministicExtractor $extractor,
        private ServiceMatcher $matcher,
        private DiscoveryRepositoryInterface $discoveries,
        private MessageStateMachine $stateMachine,
        private ImapClientInterface $imapClient,
        private CredentialCipherInterface $cipher,
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
    public function __invoke(EmailMessage $message, ?Uuid $actorUserId = null): ?Discovery
    {
        return $this->tenantContext->runAs(
            $message->getOrganizationId(),
            fn (): ?Discovery => $this->process($message, $actorUserId),
        );
    }

    private function process(EmailMessage $message, ?Uuid $actorUserId): ?Discovery
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
        $body = $this->fetchBody($message);
        $message->setContentHash(self::contentHash($body));

        $rescored = $this->billingScore->score($header, $body);
        $message->applyBillingScore($rescored->score, $rescored->reasonsAsArray());

        $document = $this->extractor->extract($header, $body);
        $message->applyClassification(
            $this->classificationOf($document),
            (int) round($document->confidence * 100),
        );
        $message->recordExtraction($document->tier, $this->extractor->name());

        $this->stateMachine->transition(
            $message,
            MessageProcessingState::EXTRACTED,
            sprintf('Extracción determinista con confianza %.2f.', $document->confidence),
            extractor: $this->extractor->name(),
            tier: $document->tier,
            data: $document->toArray(),
        );

        if (!$document->isActionable()) {
            $message->setBodyExcerpt($this->excerpt($body));
            $this->stateMachine->transition(
                $message,
                MessageProcessingState::REQUIRES_REVIEW,
                'Faltan datos para calcular un coste recurrente.',
                extractor: $this->extractor->name(),
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
            extractor: $this->extractor->name(),
            tier: $document->tier,
        );

        // ── Nivel 4: emparejamiento con el inventario de servicios ─────────
        $match = $this->matcher->match($document);

        if ($match->isHighConfidence()) {
            return $this->associate($message, $document, $match, $actorUserId);
        }

        return $this->propose($message, $document, $match, $body, $actorUserId);
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
        ?Uuid $actorUserId,
    ): ?Discovery {
        $this->stateMachine->transition(
            $message,
            MessageProcessingState::MATCHED,
            $match->explain(),
            extractor: $this->extractor->name(),
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
        ?Uuid $actorUserId,
    ): Discovery {
        $type = $match->isMediumConfidence() ? DiscoveryType::PRICE_CHANGE : DiscoveryType::NEW_SERVICE;

        $this->stateMachine->transition(
            $message,
            MessageProcessingState::DISCOVERY,
            $match->explain(),
            extractor: $this->extractor->name(),
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
    private function fetchBody(EmailMessage $message): string
    {
        $account = $this->accounts->find($message->getEmailAccountId());

        if (null === $account || !$account->isConfigured()) {
            return '';
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

            return '';
        }

        return trim($body->textBody) !== '' ? $body->textBody : $body->htmlBody;
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
