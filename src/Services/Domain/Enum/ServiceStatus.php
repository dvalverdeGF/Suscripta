<?php

declare(strict_types=1);

namespace App\Services\Domain\Enum;

/**
 * Estado del ciclo de vida de un servicio.
 *
 * `PENDING_REVIEW` existe para el descubrimiento automático: un servicio
 * propuesto por el pipeline no entra en los cálculos de coste hasta que el
 * usuario lo confirma (ARCHITECTURE.md §13.10).
 */
enum ServiceStatus: string
{
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case CANCELLED = 'cancelled';
    case PENDING_REVIEW = 'pending_review';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Activo',
            self::PAUSED => 'En pausa',
            self::CANCELLED => 'Cancelado',
            self::PENDING_REVIEW => 'Pendiente de revisar',
        };
    }

    /** Solo los servicios activos cuentan para el coste recurrente. */
    public function countsTowardsRecurringCost(): bool
    {
        return self::ACTIVE === $this;
    }

    public function isEditable(): bool
    {
        return self::CANCELLED !== $this;
    }
}
