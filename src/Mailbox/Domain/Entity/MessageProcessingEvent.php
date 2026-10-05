<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Entity;

use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Log append-only de las transiciones de estado de un mensaje (ARCHITECTURE.md
 * §13.5, D-32).
 *
 * Responde a *qué ocurrió, cuándo, con qué extractor, si intervino IA, con qué
 * resultado y por qué se decidió*. Es la base de la observabilidad del pipeline
 * y de la explicación que se le muestra al usuario.
 *
 * Nunca se actualiza ni se borra una fila: es un registro histórico.
 */
#[ORM\Entity]
#[ORM\Table(name: 'message_processing_event')]
#[ORM\Index(name: 'idx_message_processing_event_message', columns: ['email_message_id', 'occurred_at'])]
class MessageProcessingEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'email_message_id', type: 'uuid')]
    private Uuid $emailMessageId;

    #[ORM\Column(name: 'from_state', type: Types::STRING, length: 20, enumType: MessageProcessingState::class, nullable: true)]
    private ?MessageProcessingState $fromState;

    #[ORM\Column(name: 'to_state', type: Types::STRING, length: 20, enumType: MessageProcessingState::class)]
    private MessageProcessingState $toState;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $reason;

    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $extractor = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ExtractionTier::class, nullable: true)]
    private ?ExtractionTier $tier = null;

    #[ORM\Column(name: 'ai_usage_id', type: 'uuid', nullable: true)]
    private ?Uuid $aiUsageId = null;

    #[ORM\Column(name: 'duration_ms', type: Types::INTEGER, nullable: true)]
    private ?int $durationMs = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $data = [];

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Uuid $emailMessageId,
        ?MessageProcessingState $fromState,
        MessageProcessingState $toState,
        string $reason,
        DateTimeImmutable $occurredAt,
        ?string $extractor = null,
        ?ExtractionTier $tier = null,
        ?Uuid $aiUsageId = null,
        ?int $durationMs = null,
        array $data = [],
    ) {
        $this->id = Uuid::v7();
        $this->emailMessageId = $emailMessageId;
        $this->fromState = $fromState;
        $this->toState = $toState;
        $this->reason = mb_substr($reason, 0, 255);
        $this->occurredAt = $occurredAt;
        $this->extractor = null === $extractor ? null : mb_substr($extractor, 0, 80);
        $this->tier = $tier;
        $this->aiUsageId = $aiUsageId;
        $this->durationMs = $durationMs;
        $this->data = $data;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmailMessageId(): Uuid
    {
        return $this->emailMessageId;
    }

    public function getFromState(): ?MessageProcessingState
    {
        return $this->fromState;
    }

    public function getToState(): MessageProcessingState
    {
        return $this->toState;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getExtractor(): ?string
    {
        return $this->extractor;
    }

    public function getTier(): ?ExtractionTier
    {
        return $this->tier;
    }

    public function getAiUsageId(): ?Uuid
    {
        return $this->aiUsageId;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
