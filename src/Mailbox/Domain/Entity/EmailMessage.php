<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Entity;

use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Mailbox\Domain\Enum\MessageClassification;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Shared\Domain\Contract\TenantAwareInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * Un correo del buzón, reducido a lo que el pipeline necesita (ARCHITECTURE.md
 * §4.5).
 *
 * **No se guarda el cuerpo del mensaje** (D-10). Se guardan metadatos, un hash
 * del contenido y, como mucho, un extracto corto cuando hay un descubrimiento
 * que el usuario debe revisar. El cuerpo completo se descarga, se analiza y se
 * descarta.
 *
 * La identidad es triple (§13.2): `(cuenta, carpeta, uid)` como clave primaria
 * de deduplicación, `(cuenta, messageId)` como clave secundaria para detectar
 * mensajes movidos de carpeta, y `contentHash` como clave de caché de
 * extracción.
 */
#[ORM\Entity]
#[ORM\Table(name: 'email_message')]
#[ORM\UniqueConstraint(name: 'uniq_email_message_account_folder_uid', columns: ['email_account_id', 'folder', 'uid'])]
#[ORM\Index(name: 'idx_email_message_account_message_id', columns: ['email_account_id', 'message_id'])]
#[ORM\Index(name: 'idx_email_message_content_hash', columns: ['content_hash'])]
#[ORM\Index(name: 'idx_email_message_organization_state', columns: ['organization_id', 'processing_state'])]
class EmailMessage implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    #[ORM\Column(name: 'email_account_id', type: 'uuid')]
    private Uuid $emailAccountId;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $folder;

    #[ORM\Column(type: Types::INTEGER)]
    private int $uid;

    #[ORM\Column(name: 'message_id', type: Types::STRING, length: 255, nullable: true)]
    private ?string $messageId = null;

    #[ORM\Column(name: 'from_address', type: Types::STRING, length: 180, nullable: true)]
    private ?string $fromAddress = null;

    #[ORM\Column(name: 'from_name', type: Types::STRING, length: 180, nullable: true)]
    private ?string $fromName = null;

    #[ORM\Column(name: 'reply_to', type: Types::STRING, length: 180, nullable: true)]
    private ?string $replyTo = null;

    #[ORM\Column(name: 'sender_domain', type: Types::STRING, length: 180, nullable: true)]
    private ?string $senderDomain = null;

    /** @var list<string> */
    #[ORM\Column(name: 'to_addresses', type: Types::JSON)]
    private array $toAddresses = [];

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(name: 'received_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $receivedAt = null;

    #[ORM\Column(name: 'size_bytes', type: Types::INTEGER, nullable: true)]
    private ?int $sizeBytes = null;

    #[ORM\Column(name: 'content_type', type: Types::STRING, length: 120, nullable: true)]
    private ?string $contentType = null;

    #[ORM\Column(name: 'has_attachments', type: Types::BOOLEAN)]
    private bool $hasAttachments = false;

    /** @var list<string> */
    #[ORM\Column(name: 'attachment_names', type: Types::JSON)]
    private array $attachmentNames = [];

    /** @var list<string> */
    #[ORM\Column(name: 'attachment_types', type: Types::JSON)]
    private array $attachmentTypes = [];

    /**
     * SHA-256 del contenido normalizado. Es la clave de caché de extracción: si
     * ya existe una extracción válida para este hash, no se vuelve a llamar a
     * IA (§13.2, D-37).
     */
    #[ORM\Column(name: 'content_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $contentHash = null;

    /**
     * Extracto corto del cuerpo, solo cuando hay un descubrimiento pendiente de
     * revisar. Se recorta a 500 caracteres y se borra al confirmar o descartar.
     */
    #[ORM\Column(name: 'body_excerpt', type: Types::TEXT, nullable: true)]
    private ?string $bodyExcerpt = null;

    #[ORM\Column(name: 'billing_score', type: Types::SMALLINT)]
    private int $billingScore = 0;

    /** @var list<array{signal: string, weight: int, detail?: string}> */
    #[ORM\Column(name: 'billing_reasons', type: Types::JSON)]
    private array $billingReasons = [];

    #[ORM\Column(name: 'processing_state', type: Types::STRING, length: 20, enumType: MessageProcessingState::class)]
    private MessageProcessingState $processingState = MessageProcessingState::RECEIVED;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: MessageClassification::class)]
    private MessageClassification $classification = MessageClassification::UNKNOWN;

    #[ORM\Column(name: 'classification_confidence', type: Types::SMALLINT)]
    private int $classificationConfidence = 0;

    #[ORM\Column(name: 'extraction_tier', type: Types::STRING, length: 20, enumType: ExtractionTier::class, nullable: true)]
    private ?ExtractionTier $extractionTier = null;

    #[ORM\Column(name: 'extractor_used', type: Types::STRING, length: 80, nullable: true)]
    private ?string $extractorUsed = null;

    #[ORM\Column(name: 'ai_used', type: Types::BOOLEAN)]
    private bool $aiUsed = false;

    #[ORM\Column(name: 'ai_cost_minor', type: Types::INTEGER)]
    private int $aiCostMinor = 0;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $attempts = 0;

    #[ORM\Column(name: 'last_error', type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'processed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $processedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $organizationId,
        Uuid $emailAccountId,
        string $folder,
        int $uid,
    ) {
        if ($uid < 1) {
            throw new InvalidArgumentException(sprintf('El UID de IMAP debe ser positivo, se recibió %d.', $uid));
        }

        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->emailAccountId = $emailAccountId;
        $this->folder = $folder;
        $this->uid = $uid;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrganizationId(): Uuid
    {
        return $this->organizationId;
    }

    public function setOrganizationId(Uuid $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    public function getEmailAccountId(): Uuid
    {
        return $this->emailAccountId;
    }

    public function getFolder(): string
    {
        return $this->folder;
    }

    public function getUid(): int
    {
        return $this->uid;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getFromAddress(): ?string
    {
        return $this->fromAddress;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

    public function getSenderDomain(): ?string
    {
        return $this->senderDomain;
    }

    /** @return list<string> */
    public function getToAddresses(): array
    {
        return $this->toAddresses;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function getReceivedAt(): ?DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getSizeBytes(): ?int
    {
        return $this->sizeBytes;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function hasAttachments(): bool
    {
        return $this->hasAttachments;
    }

    /** @return list<string> */
    public function getAttachmentNames(): array
    {
        return $this->attachmentNames;
    }

    /** @return list<string> */
    public function getAttachmentTypes(): array
    {
        return $this->attachmentTypes;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function getBodyExcerpt(): ?string
    {
        return $this->bodyExcerpt;
    }

    public function getBillingScore(): int
    {
        return $this->billingScore;
    }

    /** @return list<array{signal: string, weight: int, detail?: string}> */
    public function getBillingReasons(): array
    {
        return $this->billingReasons;
    }

    public function getProcessingState(): MessageProcessingState
    {
        return $this->processingState;
    }

    public function getClassification(): MessageClassification
    {
        return $this->classification;
    }

    public function getClassificationConfidence(): int
    {
        return $this->classificationConfidence;
    }

    public function getExtractionTier(): ?ExtractionTier
    {
        return $this->extractionTier;
    }

    public function getExtractorUsed(): ?string
    {
        return $this->extractorUsed;
    }

    public function isAiUsed(): bool
    {
        return $this->aiUsed;
    }

    public function getAiCostMinor(): int
    {
        return $this->aiCostMinor;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getProcessedAt(): ?DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Vuelca los metadatos del nivel 1. Es idempotente: volver a aplicar los
     * mismos metadatos no cambia nada.
     *
     * @param list<string> $toAddresses
     * @param list<string> $attachmentNames
     * @param list<string> $attachmentTypes
     */
    public function applyMetadata(
        ?string $messageId,
        ?string $fromAddress,
        ?string $fromName,
        ?string $replyTo,
        ?string $senderDomain,
        array $toAddresses,
        ?string $subject,
        ?DateTimeImmutable $receivedAt,
        ?int $sizeBytes,
        ?string $contentType,
        array $attachmentNames,
        array $attachmentTypes,
    ): void {
        $this->messageId = null === $messageId ? null : mb_substr(trim($messageId), 0, 255);
        $this->fromAddress = null === $fromAddress ? null : mb_strtolower(mb_substr(trim($fromAddress), 0, 180));
        $this->fromName = null === $fromName ? null : (mb_substr(trim($fromName), 0, 180) ?: null);
        $this->replyTo = null === $replyTo ? null : mb_strtolower(mb_substr(trim($replyTo), 0, 180));
        $this->senderDomain = null === $senderDomain ? null : mb_strtolower(mb_substr(trim($senderDomain), 0, 180));
        $this->toAddresses = $toAddresses;
        $this->subject = null === $subject ? null : mb_substr(trim($subject), 0, 500);
        $this->receivedAt = $receivedAt;
        $this->sizeBytes = $sizeBytes;
        $this->contentType = null === $contentType ? null : mb_substr(trim($contentType), 0, 120);
        $this->attachmentNames = $attachmentNames;
        $this->attachmentTypes = $attachmentTypes;
        $this->hasAttachments = [] !== $attachmentNames;
    }

    public function setContentHash(?string $contentHash): void
    {
        $this->contentHash = $contentHash;
    }

    public function setBodyExcerpt(?string $bodyExcerpt): void
    {
        $this->bodyExcerpt = null === $bodyExcerpt ? null : mb_substr($bodyExcerpt, 0, 500);
    }

    /**
     * @param list<array{signal: string, weight: int, detail?: string}> $reasons
     */
    public function applyBillingScore(int $score, array $reasons): void
    {
        $this->billingScore = max(0, min(100, $score));
        $this->billingReasons = $reasons;
    }

    public function setProcessingState(MessageProcessingState $state): void
    {
        $this->processingState = $state;
    }

    public function applyClassification(MessageClassification $classification, int $confidence): void
    {
        $this->classification = $classification;
        $this->classificationConfidence = max(0, min(100, $confidence));
    }

    public function recordExtraction(ExtractionTier $tier, string $extractorUsed, bool $aiUsed = false, int $aiCostMinor = 0): void
    {
        $this->extractionTier = $tier;
        $this->extractorUsed = mb_substr($extractorUsed, 0, 80);
        $this->aiUsed = $aiUsed;
        $this->aiCostMinor += $aiCostMinor;
    }

    /**
     * Marca el inicio de un intento de procesamiento.
     *
     * `lastError` describe el **intento en curso**, no la historia del mensaje:
     * se limpia al empezar y solo se vuelve a rellenar si algo falla. Si se
     * limpiara al terminar (como hacía `markProcessed()`), un fallo de red
     * quedaría borrado justo antes de que nadie pudiera leerlo, y el usuario
     * vería un mensaje "pendiente de revisión" sin saber por qué.
     */
    public function beginProcessingAttempt(): void
    {
        $this->lastError = null;
    }

    public function markProcessed(DateTimeImmutable $at): void
    {
        $this->processedAt = $at;
    }

    public function recordFailure(string $error): void
    {
        ++$this->attempts;
        $this->lastError = mb_substr($error, 0, 2000);
    }
}
