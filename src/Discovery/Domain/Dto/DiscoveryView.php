<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Dto;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryConfidence;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Mailbox\Domain\Enum\ExtractionTier;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Vista de lectura de una propuesta de descubrimiento (ARCHITECTURE.md §3).
 *
 * `proposedData` y `matchReasons` viajan como estructuras libres porque su
 * forma depende del tipo de propuesta; el resto de campos son planos y estables.
 */
final readonly class DiscoveryView
{
    /**
     * @param list<array{signal: string, weight: int, detail?: string|null}> $matchReasons
     * @param array<string, mixed>                                           $proposedData
     */
    public function __construct(
        public Uuid $id,
        public string $type,
        public string $typeLabel,
        public string $status,
        public string $statusLabel,
        public string $confidence,
        public int $confidenceScore,
        public int $matchScore,
        public array $matchReasons,
        public array $proposedData,
        public ?Uuid $matchedServiceId,
        public ?Uuid $sourceEmailMessageId,
        public ?string $extractionTier,
        public bool $aiUsed,
        public bool $inference,
        public DateTimeImmutable $detectedAt,
        public ?DateTimeImmutable $reviewedAt,
        public ?Uuid $resultingServiceId,
    ) {
    }

    public static function fromDiscovery(Discovery $discovery): self
    {
        return new self(
            id: $discovery->getId(),
            type: $discovery->getType()->value,
            typeLabel: $discovery->getType()->label(),
            status: $discovery->getStatus()->value,
            statusLabel: $discovery->getStatus()->label(),
            confidence: $discovery->getConfidence()->value,
            confidenceScore: $discovery->getConfidenceScore(),
            matchScore: $discovery->getMatchScore(),
            // Las razones ya son estructuras (`signal`, `weight`, `detail`): se
            // copian tal cual. Convertirlas a texto las dejaba en «Array» y la
            // API publicaba una lista de cadenas vacías de contenido.
            matchReasons: array_values($discovery->getMatchReasons()),
            proposedData: $discovery->getProposedData(),
            matchedServiceId: $discovery->getMatchedServiceId(),
            sourceEmailMessageId: $discovery->getSourceEmailMessageId(),
            extractionTier: $discovery->getExtractionTier()?->value,
            aiUsed: $discovery->isAiUsed(),
            inference: $discovery->isInference(),
            detectedAt: $discovery->getDetectedAt(),
            reviewedAt: $discovery->getReviewedAt(),
            resultingServiceId: $discovery->getResultingServiceId(),
        );
    }

    public function statusEnum(): DiscoveryStatus
    {
        return DiscoveryStatus::from($this->status);
    }

    public function typeEnum(): DiscoveryType
    {
        return DiscoveryType::from($this->type);
    }

    public function confidenceEnum(): DiscoveryConfidence
    {
        return DiscoveryConfidence::from($this->confidence);
    }

    public function extractionTierEnum(): ?ExtractionTier
    {
        return null === $this->extractionTier ? null : ExtractionTier::from($this->extractionTier);
    }
}
