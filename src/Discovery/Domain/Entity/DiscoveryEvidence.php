<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Entity;

use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function max;
use function min;

use Symfony\Component\Uid\Uuid;

/**
 * Por qué el sistema propone algo (ARCHITECTURE.md §4.6).
 *
 * Apunta al correo y, más adelante, al documento que sostienen la propuesta. Es
 * lo que permite responder a *"¿de dónde te sacas esto?"* con un enlace al
 * mensaje original en lugar de con una afirmación sin respaldo.
 *
 * No implementa `TenantAwareInterface`: se accede siempre a través de su
 * `Discovery`, que ya está acotado por organización.
 */
#[ORM\Entity]
#[ORM\Table(name: 'discovery_evidence')]
#[ORM\Index(name: 'idx_discovery_evidence_discovery', columns: ['discovery_id'])]
class DiscoveryEvidence
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'discovery_id', type: 'uuid')]
    private Uuid $discoveryId;

    #[ORM\Column(name: 'email_message_id', type: 'uuid', nullable: true)]
    private ?Uuid $emailMessageId = null;

    #[ORM\Column(name: 'document_id', type: 'uuid', nullable: true)]
    private ?Uuid $documentId = null;

    /**
     * Peso relativo de esta evidencia dentro de la propuesta. Permite ordenar
     * las evidencias por relevancia en lugar de por fecha.
     */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $weight = 100;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $discoveryId,
        DateTimeImmutable $createdAt,
        ?Uuid $emailMessageId = null,
        ?Uuid $documentId = null,
        int $weight = 100,
    ) {
        if (null === $emailMessageId && null === $documentId) {
            throw new InvalidArgumentException('Una evidencia debe apuntar al menos a un correo o a un documento.');
        }

        $this->id = Uuid::v7();
        $this->discoveryId = $discoveryId;
        $this->createdAt = $createdAt;
        $this->emailMessageId = $emailMessageId;
        $this->documentId = $documentId;
        $this->weight = max(0, min(100, $weight));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDiscoveryId(): Uuid
    {
        return $this->discoveryId;
    }

    public function getEmailMessageId(): ?Uuid
    {
        return $this->emailMessageId;
    }

    public function getDocumentId(): ?Uuid
    {
        return $this->documentId;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
