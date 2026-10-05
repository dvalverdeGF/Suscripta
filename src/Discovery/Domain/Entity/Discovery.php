<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Entity;

use App\Discovery\Domain\Enum\DiscoveryConfidence;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Shared\Domain\Contract\TenantAwareInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function is_scalar;
use function max;
use function mb_substr;
use function min;
use function sprintf;

use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Una propuesta que el sistema hace al usuario (ARCHITECTURE.md §4.6).
 *
 * **Nunca se crea un `Service` automáticamente.** Cuando la confianza no es
 * suficiente, el pipeline crea un `Discovery` en estado `pending` y espera. Es
 * la materialización de la regla de producto: el sistema propone, el usuario
 * decide.
 *
 * `proposedData` es un JSON deliberadamente: es una propuesta en revisión, no
 * una entidad de negocio, y su forma evolucionará con los extractores (D-14).
 *
 * `Discovery` es **tenant-scoped, no cuenta-scoped** (D-27): la misma factura
 * puede llegar por dos buzones de la organización y no debe proponerse dos
 * veces.
 */
#[ORM\Entity]
#[ORM\Table(name: 'discovery')]
#[ORM\Index(name: 'idx_discovery_organization_status', columns: ['organization_id', 'status'])]
#[ORM\Index(name: 'idx_discovery_source_message', columns: ['source_email_message_id'])]
#[ORM\Index(name: 'idx_discovery_dedup_key', columns: ['organization_id', 'dedup_key'])]
class Discovery implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: DiscoveryType::class)]
    private DiscoveryType $type;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: DiscoveryStatus::class)]
    private DiscoveryStatus $status = DiscoveryStatus::PENDING;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: DiscoveryConfidence::class)]
    private DiscoveryConfidence $confidence = DiscoveryConfidence::LOW;

    #[ORM\Column(name: 'confidence_score', type: Types::SMALLINT)]
    private int $confidenceScore = 0;

    #[ORM\Column(name: 'match_score', type: Types::SMALLINT)]
    private int $matchScore = 0;

    /** @var list<array{signal: string, weight: int, detail?: string|null}> */
    #[ORM\Column(name: 'match_reasons', type: Types::JSON)]
    private array $matchReasons = [];

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'proposed_data', type: Types::JSON)]
    private array $proposedData = [];

    #[ORM\Column(name: 'matched_service_id', type: 'uuid', nullable: true)]
    private ?Uuid $matchedServiceId = null;

    #[ORM\Column(name: 'source_email_message_id', type: 'uuid', nullable: true)]
    private ?Uuid $sourceEmailMessageId = null;

    #[ORM\Column(name: 'extraction_tier', type: Types::STRING, length: 20, enumType: ExtractionTier::class, nullable: true)]
    private ?ExtractionTier $extractionTier = null;

    #[ORM\Column(name: 'ai_used', type: Types::BOOLEAN)]
    private bool $aiUsed = false;

    #[ORM\Column(name: 'detected_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $detectedAt;

    #[ORM\Column(name: 'reviewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(name: 'reviewed_by_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $reviewedByUserId = null;

    #[ORM\Column(name: 'resulting_service_id', type: 'uuid', nullable: true)]
    private ?Uuid $resultingServiceId = null;

    /**
     * Clave de deduplicación entre cuentas (D-27): dos buzones que reciben la
     * misma factura no deben generar dos propuestas.
     *
     * Se persiste como columna —y no se calcula al vuelo— porque es una clave
     * de búsqueda: sin índice, la comprobación de duplicados recorrería todas
     * las propuestas pendientes de la organización.
     */
    #[ORM\Column(name: 'dedup_key', type: Types::STRING, length: 255)]
    private string $dedupKey;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * @param array<string, mixed> $proposedData
     */
    public function __construct(
        Uuid $organizationId,
        DiscoveryType $type,
        DateTimeImmutable $detectedAt,
        array $proposedData = [],
    ) {
        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->type = $type;
        $this->detectedAt = $detectedAt;
        $this->proposedData = $proposedData;
        $this->dedupKey = self::buildDedupKey($type, $proposedData);
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

    public function getType(): DiscoveryType
    {
        return $this->type;
    }

    public function getStatus(): DiscoveryStatus
    {
        return $this->status;
    }

    public function getConfidence(): DiscoveryConfidence
    {
        return $this->confidence;
    }

    public function getConfidenceScore(): int
    {
        return $this->confidenceScore;
    }

    public function getMatchScore(): int
    {
        return $this->matchScore;
    }

    /** @return list<array{signal: string, weight: int, detail?: string|null}> */
    public function getMatchReasons(): array
    {
        return $this->matchReasons;
    }

    /** @return array<string, mixed> */
    public function getProposedData(): array
    {
        return $this->proposedData;
    }

    public function getMatchedServiceId(): ?Uuid
    {
        return $this->matchedServiceId;
    }

    public function getSourceEmailMessageId(): ?Uuid
    {
        return $this->sourceEmailMessageId;
    }

    public function getExtractionTier(): ?ExtractionTier
    {
        return $this->extractionTier;
    }

    public function isAiUsed(): bool
    {
        return $this->aiUsed;
    }

    public function getDetectedAt(): DateTimeImmutable
    {
        return $this->detectedAt;
    }

    public function getReviewedAt(): ?DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getReviewedByUserId(): ?Uuid
    {
        return $this->reviewedByUserId;
    }

    public function getResultingServiceId(): ?Uuid
    {
        return $this->resultingServiceId;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = null === $notes ? null : (trim($notes) ?: null);
    }

    /**
     * @param list<array{signal: string, weight: int, detail?: string|null}> $reasons
     */
    public function applyMatch(int $score, array $reasons, ?Uuid $matchedServiceId = null): void
    {
        $this->matchScore = max(0, min(100, $score));
        $this->matchReasons = $reasons;
        $this->matchedServiceId = $matchedServiceId;
    }

    public function applyConfidence(int $score): void
    {
        $this->confidenceScore = max(0, min(100, $score));
        $this->confidence = DiscoveryConfidence::fromScore($this->confidenceScore);
    }

    public function recordExtraction(ExtractionTier $tier, bool $aiUsed = false): void
    {
        $this->extractionTier = $tier;
        $this->aiUsed = $aiUsed;
    }

    public function attachSourceMessage(?Uuid $emailMessageId): void
    {
        $this->sourceEmailMessageId = $emailMessageId;
    }

    /**
     * @param array<string, mixed> $proposedData
     */
    public function updateProposal(array $proposedData): void
    {
        $this->proposedData = $proposedData;
        $this->dedupKey = self::buildDedupKey($this->type, $proposedData);
    }

    public function confirm(Uuid $userId, DateTimeImmutable $at, ?Uuid $resultingServiceId = null, bool $edited = false): void
    {
        $this->assertPending();

        $this->status = $edited ? DiscoveryStatus::EDITED : DiscoveryStatus::CONFIRMED;
        $this->reviewedAt = $at;
        $this->reviewedByUserId = $userId;
        $this->resultingServiceId = $resultingServiceId;
    }

    public function ignore(Uuid $userId, DateTimeImmutable $at, ?string $reason = null): void
    {
        $this->assertPending();

        $this->status = DiscoveryStatus::IGNORED;
        $this->reviewedAt = $at;
        $this->reviewedByUserId = $userId;
        $this->notes = null === $reason ? null : (trim($reason) ?: null);
    }

    public function expire(DateTimeImmutable $at): void
    {
        if (!$this->status->isPending()) {
            return;
        }

        $this->status = DiscoveryStatus::EXPIRED;
        $this->reviewedAt = $at;
    }

    /**
     * Clave de deduplicación entre cuentas (D-27): dos buzones que reciben la
     * misma factura no deben generar dos propuestas.
     */
    public function getDedupKey(): string
    {
        return $this->dedupKey;
    }

    /**
     * @param array<string, mixed> $proposedData
     */
    public static function buildDedupKey(DiscoveryType $type, array $proposedData): string
    {
        return mb_substr(sprintf(
            '%s|%s|%s|%s|%s',
            $type->value,
            self::stringify($proposedData['providerName'] ?? null),
            self::stringify($proposedData['amountMinor'] ?? null),
            self::stringify($proposedData['currency'] ?? null),
            self::stringify($proposedData['billingPeriod'] ?? null),
        ), 0, 255);
    }

    /**
     * `proposedData` es JSON libre, así que puede contener cualquier cosa. La
     * clave de deduplicación solo necesita una representación estable, no
     * interpretar el valor.
     */
    private static function stringify(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    private function assertPending(): void
    {
        if (!$this->status->isPending()) {
            throw new InvalidArgumentException(sprintf('El descubrimiento ya está %s.', $this->status->label()));
        }
    }
}
