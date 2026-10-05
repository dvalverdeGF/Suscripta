<?php

declare(strict_types=1);

namespace App\Services\Domain\Dto;

use App\Services\Domain\Entity\ServicePrice;
use App\Services\Domain\Enum\ServiceSource;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

/**
 * Una fila del historial de precios, con lo que cambió respecto a la anterior.
 *
 * La variación se calcula al construir la fila y no en la vista: es una regla
 * de negocio (comparar filas consecutivas) y tiene que poder probarse sin
 * renderizar nada.
 */
final readonly class PriceHistoryEntry
{
    public function __construct(
        public ServicePrice $price,
        public Money $amount,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validTo,
        public ServiceSource $source,
        public ?string $note,
        public bool $isCurrent,
        public ?Money $variationAbsolute,
        public ?float $variationRatio,
    ) {
    }

    public function hasVariation(): bool
    {
        return null !== $this->variationAbsolute && !$this->variationAbsolute->isZero();
    }

    public function isIncrease(): bool
    {
        return null !== $this->variationAbsolute && $this->variationAbsolute->amountMinor > 0;
    }
}
