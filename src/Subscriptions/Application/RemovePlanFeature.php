<?php

declare(strict_types=1);

namespace App\Subscriptions\Application;

use App\Subscriptions\Domain\Entity\PlanFeature;
use App\Subscriptions\Domain\Repository\PlanFeatureRepositoryInterface;

/**
 * Quita una característica de la lista.
 *
 * Aquí sí se borra, y no se «cierra» como los precios: una característica es
 * texto de presentación, no un dato con fecha que alguien pueda necesitar
 * reconstruir. Una errata no merece historial.
 */
final readonly class RemovePlanFeature
{
    public function __construct(
        private PlanFeatureRepositoryInterface $features,
    ) {
    }

    public function __invoke(PlanFeature $feature): void
    {
        $this->features->remove($feature);
    }
}
