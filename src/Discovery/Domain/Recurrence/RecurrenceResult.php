<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Recurrence;

use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;

/**
 * Resultado de detectar la periodicidad de un servicio a partir de sus cobros.
 *
 * La regla que manda es la de ARCHITECTURE.md §14: un cobro irregular se marca
 * con **confianza baja** y **no** genera fecha de cobro automática. Es preferible
 * que el dashboard no muestre un próximo cobro antes que invente una fecha que
 * el usuario no espera.
 */
final readonly class RecurrenceResult
{
    /**
     * Umbral a partir del cual la detección se considera fiable.
     *
     * Por debajo de este listón la detección se muestra pero no se aplica sola:
     * el usuario tiene que confirmarla.
     */
    public const float CONFIDENCE_THRESHOLD = 0.65;

    /**
     * @param list<int> $intervalsDays distancias en días entre cobros consecutivos
     */
    public function __construct(
        public ?BillingPeriod $period = null,
        public bool $isRegular = false,
        public float $confidence = 0.0,
        public ?DateTimeImmutable $lastChargeAt = null,
        public ?DateTimeImmutable $nextChargeAt = null,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?int $previousAmountMinor = null,
        public bool $amountChanged = false,
        public array $intervalsDays = [],
        public string $explanation = '',
    ) {
    }

    /**
     * ¿Se ha deducido una periodicidad con nombre (mensual, anual, ...)?
     */
    public function hasPeriod(): bool
    {
        return null !== $this->period;
    }

    /**
     * Confianza alta: el patrón es regular y hay historial suficiente.
     *
     * Por debajo de este listón la detección se muestra pero no se aplica
     * sola: el usuario tiene que confirmarla.
     */
    public function isConfident(): bool
    {
        return $this->isRegular && $this->confidence >= self::CONFIDENCE_THRESHOLD;
    }

    /**
     * Variación relativa del importe respecto al cobro anterior (0.10 = +10 %).
     *
     * Null si no hay historial de importes o si el importe anterior era cero.
     */
    public function amountChangeRatio(): ?float
    {
        if (null === $this->amountMinor || null === $this->previousAmountMinor) {
            return null;
        }

        if (0 === $this->previousAmountMinor) {
            return null;
        }

        return ($this->amountMinor - $this->previousAmountMinor) / $this->previousAmountMinor;
    }
}
