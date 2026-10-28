<?php

declare(strict_types=1);

namespace App\Subscriptions\Application;

use App\Subscriptions\Domain\Entity\Plan;
use App\Subscriptions\Domain\Entity\PlanFeature;
use App\Subscriptions\Domain\Repository\PlanFeatureRepositoryInterface;

/**
 * Añade una característica a la lista de un plan.
 *
 * La posición se calcula al final de la lista: lo normal es añadir y que
 * aparezca debajo de lo anterior, y reordenar es una decisión explícita.
 */
final readonly class AddPlanFeature
{
    public function __construct(
        private PlanFeatureRepositoryInterface $features,
    ) {
    }

    public function __invoke(Plan $plan, string $label, bool $included = true): PlanFeature
    {
        $feature = new PlanFeature(
            $plan,
            $label,
            $included,
            $this->features->nextPosition($plan->getId()),
        );

        $this->features->save($feature);

        return $feature;
    }
}
