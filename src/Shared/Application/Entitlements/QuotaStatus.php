<?php

declare(strict_types=1);

namespace App\Shared\Application\Entitlements;

/**
 * Foto del consumo de una capacidad (Fase 17).
 *
 * Es lo que se pinta en la interfaz: cuánto se usa, cuál es el tope y si ya
 * se ha llegado. Se devuelve como objeto y no como array porque la forma de
 * los datos de la interfaz no debería decidir la forma de los datos del
 * dominio.
 */
final readonly class QuotaStatus
{
    public function __construct(
        public string $capability,
        public string $label,
        public ?string $unit,
        public int $usage,
        public ?int $limit,
        public bool $allowed,
    ) {
    }

    /**
     * ¿El plan no pone techo a esta capacidad?
     */
    public function isUnlimited(): bool
    {
        return null === $this->limit;
    }

    /**
     * ¿El plan no ofrece esta capacidad?
     */
    public function isBlocked(): bool
    {
        return !$this->allowed;
    }

    /**
     * ¿Se ha agotado el límite?
     */
    public function isExhausted(): bool
    {
        if (!$this->allowed || $this->isUnlimited()) {
            return false;
        }

        return $this->usage >= $this->limit;
    }

    /**
     * Cuántas unidades quedan. `null` si no hay techo.
     */
    public function remaining(): ?int
    {
        if (null === $this->limit) {
            return null;
        }

        return max(0, $this->limit - $this->usage);
    }

    /**
     * Porcentaje de uso, redondeado. `null` si no hay techo.
     */
    public function usedPercent(): ?int
    {
        if (null === $this->limit || $this->limit <= 0) {
            return null;
        }

        return (int) round(($this->usage / $this->limit) * 100);
    }
}
