<?php

declare(strict_types=1);

namespace App\Notifications\Application\Dto;

use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertType;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Un aviso que el generador ha decidido que debería existir.
 *
 * Es un DTO y no una entidad porque la decisión («esto merece un aviso») es
 * independiente de si el aviso ya existía: el generador calcula candidatos y
 * después los concilia con lo que hay en la base de datos.
 */
final readonly class AlertCandidate
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public AlertType $type,
        public AlertSeverity $severity,
        public string $title,
        public string $message,
        public string $dedupKey,
        public ?DateTimeImmutable $dueAt = null,
        public ?Uuid $serviceId = null,
        public ?Uuid $discoveryId = null,
        public array $metadata = [],
    ) {
    }
}
