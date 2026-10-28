<?php

declare(strict_types=1);

namespace App\Subscriptions\Application;

use App\Subscriptions\Domain\Entity\PlanFeature;
use App\Subscriptions\Domain\Repository\PlanFeatureRepositoryInterface;

/**
 * Corrige el texto de una característica o cambia si entra en el plan.
 */
final readonly class UpdatePlanFeature
{
    public function __construct(
        private PlanFeatureRepositoryInterface $features,
    ) {
    }

    public function __invoke(PlanFeature $feature, string $label, bool $included): void
    {
        $feature->rename($label);
        $feature->setIncluded($included);

        $this->features->save($feature);
    }
}
