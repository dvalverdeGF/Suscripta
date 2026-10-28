<?php

declare(strict_types=1);

namespace App\Subscriptions\Application;

use App\Subscriptions\Application\Dto\PlanInput;
use App\Subscriptions\Domain\Entity\Plan;
use App\Subscriptions\Domain\Repository\PlanRepositoryInterface;

/**
 * Guarda los datos comerciales de un plan.
 *
 * El código no se toca: cambiarlo sería convertir el plan en otro y dejar a las
 * suscripciones que lo referencian apuntando a un plan que ya no es el suyo.
 */
final readonly class UpdatePlan
{
    public function __construct(
        private PlanRepositoryInterface $plans,
    ) {
    }

    public function __invoke(Plan $plan, PlanInput $input): void
    {
        $plan->rename($input->name);
        $plan->describe($input->tagline, $input->description);
        $plan->setActive($input->isActive);
        $plan->setHighlighted($input->isHighlighted);
        $plan->moveTo($input->position);

        $this->plans->save($plan);
    }
}
