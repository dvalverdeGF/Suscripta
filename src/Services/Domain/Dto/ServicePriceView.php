<?php

declare(strict_types=1);

namespace App\Services\Domain\Dto;

use App\Services\Domain\Entity\ServicePrice;
use App\Services\Domain\Enum\ServiceSource;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Un precio del historial, visto desde fuera del módulo `Services`.
 *
 * Es un **DTO, no la entidad `ServicePrice`**: los módulos que leen el
 * inventario (dashboard, notificaciones, processing) no necesitan el historial
 * ni pueden escribirlo, y acoplarles a la entidad les daría permisos que no
 * usan (ARCHITECTURE.md §3).
 *
 * La fila se identifica por `id`, que es lo que permite mantener la **identidad
 * de objeto** dentro del modelo de lectura: `ServiceView::$currentPrice` es
 * exactamente la misma instancia que aparece en `ServiceView::$prices`, y eso es
 * lo que permite saber cuál es el precio vigente sin volver a comparar
 * importes.
 */
final readonly class ServicePriceView
{
    public function __construct(
        public Uuid $id,
        public Money $amount,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validTo,
        public ServiceSource $source,
        public ?string $note,
        public bool $isCurrent,
    ) {
    }

    public static function fromPrice(ServicePrice $price): self
    {
        return new self(
            id: $price->getId(),
            amount: $price->getAmount(),
            validFrom: $price->getValidFrom(),
            validTo: $price->getValidTo(),
            source: $price->getSource(),
            note: $price->getNote(),
            isCurrent: $price->isCurrent(),
        );
    }

    /**
     * ¿Estaba este precio vigente en la fecha indicada?
     */
    public function covers(DateTimeImmutable $date): bool
    {
        if ($date < $this->validFrom) {
            return false;
        }

        return null === $this->validTo || $date < $this->validTo;
    }
}
