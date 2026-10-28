<?php

declare(strict_types=1);

namespace App\Subscriptions\Domain\Repository;

use App\Subscriptions\Domain\Entity\PlanFeature;
use Symfony\Component\Uid\Uuid;

interface PlanFeatureRepositoryInterface
{
    public function find(Uuid $id): ?PlanFeature;

    /**
     * Características de un plan, en el orden en que se enseñan.
     *
     * @return list<PlanFeature>
     */
    public function findForPlan(Uuid $planId): array;

    /**
     * Posición libre al final de la lista de un plan.
     */
    public function nextPosition(Uuid $planId): int;

    public function save(PlanFeature $feature): void;

    public function remove(PlanFeature $feature): void;
}
