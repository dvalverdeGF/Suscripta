<?php

declare(strict_types=1);

namespace App\Services\Domain\Entity;

use App\Services\Domain\Enum\ServiceEventType;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Log append-only del ciclo de vida de un servicio (ARCHITECTURE.md §4.3).
 *
 * Alimenta la pregunta "¿qué ha cambiado?" del dashboard y es la base de las
 * alertas de cambio. No se edita ni se borra: si algo se corrige, se añade otro
 * evento.
 */
#[ORM\Entity]
#[ORM\Table(name: 'service_event')]
#[ORM\Index(name: 'idx_service_event_service_occurred', columns: ['service_id', 'occurred_at'])]
class ServiceEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Service::class, inversedBy: 'events')]
    #[ORM\JoinColumn(name: 'service_id', nullable: false, onDelete: 'CASCADE')]
    private Service $service;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: ServiceEventType::class)]
    private ServiceEventType $type;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $occurredAt;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: Types::JSON)]
    private array $data;

    #[ORM\Column(name: 'actor_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $actorUserId;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, scalar|null> $data
     */
    public function __construct(
        Service $service,
        ServiceEventType $type,
        DateTimeImmutable $occurredAt,
        array $data = [],
        ?Uuid $actorUserId = null,
    ) {
        $this->id = Uuid::v7();
        $this->service = $service;
        $this->type = $type;
        $this->occurredAt = $occurredAt;
        $this->data = $data;
        $this->actorUserId = $actorUserId;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getService(): Service
    {
        return $this->service;
    }

    public function getType(): ServiceEventType
    {
        return $this->type;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** @return array<string, scalar|null> */
    public function getData(): array
    {
        return $this->data;
    }

    public function getActorUserId(): ?Uuid
    {
        return $this->actorUserId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
