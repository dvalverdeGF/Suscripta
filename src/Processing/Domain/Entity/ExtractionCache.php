<?php

declare(strict_types=1);

namespace App\Processing\Domain\Entity;

use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Processing\Domain\Dto\ExtractedDocument;
use App\Shared\Domain\Contract\TenantAwareInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

use function mb_substr;
use function round;

use Symfony\Component\Uid\Uuid;

/**
 * Caché de extracción por contenido (ARCHITECTURE.md §13.2, D-37).
 *
 * La clave es el `contentHash` del cuerpo normalizado, no el mensaje: un
 * reenvío, un recordatorio o un reintento del mismo correo tienen cuerpos
 * idénticos y no deben pagar dos veces por el mismo análisis. Es la pieza que
 * hace que el coste marginal de un buzón ya procesado tienda a cero.
 *
 * Está **acotada por organización** a propósito. Compartirla entre
 * organizaciones ahorraría unas pocas extracciones y, a cambio, permitiría
 * deducir que dos clientes han recibido el mismo correo. La privacidad no se
 * negocia por un ahorro marginal (SECURITY.md §1).
 *
 * Solo se guarda el resultado estructurado, nunca el cuerpo del correo (D-10).
 */
#[ORM\Entity]
#[ORM\Table(name: 'extraction_cache')]
#[ORM\UniqueConstraint(name: 'uniq_extraction_cache_content', columns: ['organization_id', 'content_hash'])]
#[ORM\Index(name: 'idx_extraction_cache_last_used', columns: ['organization_id', 'last_used_at'])]
class ExtractionCache implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    #[ORM\Column(name: 'content_hash', type: Types::STRING, length: 64)]
    private string $contentHash;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $document;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ExtractionTier::class)]
    private ExtractionTier $tier;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $confidence;

    #[ORM\Column(name: 'hit_count', type: Types::INTEGER)]
    private int $hitCount = 0;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $lastUsedAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $organizationId,
        string $contentHash,
        ExtractedDocument $document,
        DateTimeImmutable $createdAt,
    ) {
        if ('' === $contentHash) {
            throw new InvalidArgumentException('La caché de extracción necesita un hash de contenido.');
        }

        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->contentHash = mb_substr($contentHash, 0, 64);
        $this->document = $document->toArray();
        $this->tier = $document->tier;
        $this->confidence = (int) round($document->confidence * 100);
        $this->lastUsedAt = $createdAt;
        $this->createdAt = $createdAt;
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

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getDocument(): ExtractedDocument
    {
        return ExtractedDocument::fromArray($this->document);
    }

    public function getTier(): ExtractionTier
    {
        return $this->tier;
    }

    public function getConfidence(): int
    {
        return $this->confidence;
    }

    public function getHitCount(): int
    {
        return $this->hitCount;
    }

    public function getLastUsedAt(): DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Registra un acierto. El contador no es decorativo: es lo que permite
     * podar la caché por uso (Fase 13) y medir cuánto ahorra de verdad.
     */
    public function recordHit(DateTimeImmutable $at): void
    {
        ++$this->hitCount;
        $this->lastUsedAt = $at;
    }
}
