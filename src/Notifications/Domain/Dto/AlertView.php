<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Dto;

use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertStatus;
use App\Notifications\Domain\Enum\AlertType;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Vista de lectura de una alerta (ARCHITECTURE.md §3).
 *
 * Igual que `ServiceView` en el módulo `Services`, la vista existe para que
 * quien consume alertas —el panel, la API pública— no tenga que importar la
 * entidad `Alert` y todos sus valores de enumeración.
 *
 * Los enums se exponen **por su valor técnico** (`upcoming_charge`) y no por su
 * etiqueta traducida (`Próximo cobro`): la etiqueta es para personas y el
 * valor es para programas. La etiqueta viaja además en `type_label` para quien
 * la necesite sin tener que duplicar el diccionario.
 */
final readonly class AlertView
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public Uuid $id,
        public string $type,
        public string $typeLabel,
        public string $severity,
        public string $status,
        public string $title,
        public string $message,
        public ?Uuid $serviceId,
        public ?Uuid $discoveryId,
        public ?DateTimeImmutable $dueAt,
        public array $metadata,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $resolvedAt,
        public bool $open,
    ) {
    }

    public static function fromAlert(Alert $alert): self
    {
        return new self(
            id: $alert->getId(),
            type: $alert->getType()->value,
            typeLabel: $alert->getType()->label(),
            severity: $alert->getSeverity()->value,
            status: $alert->getStatus()->value,
            title: $alert->getTitle(),
            message: $alert->getMessage(),
            serviceId: $alert->getServiceId(),
            discoveryId: $alert->getDiscoveryId(),
            dueAt: $alert->getDueAt(),
            metadata: $alert->getMetadata(),
            createdAt: $alert->getCreatedAt(),
            resolvedAt: $alert->getResolvedAt(),
            open: $alert->isOpen(),
        );
    }

    public function severityEnum(): AlertSeverity
    {
        return AlertSeverity::from($this->severity);
    }

    public function statusEnum(): AlertStatus
    {
        return AlertStatus::from($this->status);
    }

    public function typeEnum(): AlertType
    {
        return AlertType::from($this->type);
    }
}
