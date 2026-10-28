<?php

declare(strict_types=1);

namespace App\Subscriptions\Application;

use App\Subscriptions\Domain\Entity\PlanPrice;
use App\Subscriptions\Domain\Repository\PlanPriceRepositoryInterface;
use DateTimeImmutable;

/**
 * Retira un precio de la oferta.
 *
 * No se borra: se cierra. El precio deja de ofrecerse desde hoy y sigue
 * explicando lo que se cobró mientras estuvo vigente.
 */
final readonly class ClosePlanPrice
{
    public function __construct(
        private PlanPriceRepositoryInterface $prices,
    ) {
    }

    public function __invoke(PlanPrice $price, ?DateTimeImmutable $on = null): void
    {
        $price->close($on ?? new DateTimeImmutable('today'));

        $this->prices->save($price);
    }
}
