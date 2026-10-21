<?php

declare(strict_types=1);

namespace App\Services\Domain\Dto;

use App\Services\Domain\Entity\ServiceEvent;
use App\Services\Domain\Enum\ServiceEventType;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Un evento del ciclo de vida de un servicio, visto desde fuera del módulo.
 *
 * El log de eventos es de solo lectura para el resto de la aplicación: se
 * escribe desde el propio módulo `Services` y se consulta desde el dashboard y
 * las alertas. Por eso se expone como DTO y no como entidad (ARCHITECTURE.md
 * §3, §4.3).
 */
final readonly class ServiceEventView
{
    /**
     * @param array<string, scalar|null> $data
     */
    public function __construct(
        public Uuid $id,
        public ServiceEventType $type,
        public DateTimeImmutable $occurredAt,
        public array $data,
    ) {
    }

    public static function fromEvent(ServiceEvent $event): self
    {
        return new self(
            id: $event->getId(),
            type: $event->getType(),
            occurredAt: $event->getOccurredAt(),
            data: $event->getData(),
        );
    }
}
