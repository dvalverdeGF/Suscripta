<?php

declare(strict_types=1);

namespace App\Subscriptions\Domain\Repository;

use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Subscriptions\Domain\Entity\PlanPrice;
use Symfony\Component\Uid\Uuid;

interface PlanPriceRepositoryInterface
{
    public function find(Uuid $id): ?PlanPrice;

    /**
     * Precios vigentes de un plan, ordenados por periodo.
     *
     * @return list<PlanPrice>
     */
    public function findCurrentForPlan(Uuid $planId): array;

    /**
     * Precios **ya cerrados** de un plan, del más reciente al más antiguo.
     *
     * Los vigentes no salen aquí: tienen su propia consulta y mezclarlos haría
     * que el historial repitiera lo que ya se ve arriba.
     *
     * @return list<PlanPrice>
     */
    public function findHistoryForPlan(Uuid $planId): array;

    /**
     * El precio vigente para un periodo y una moneda concretos.
     *
     * Un plan puede tener a la vez un precio mensual y uno anual; lo que no
     * puede tener es dos precios mensuales vigentes en euros.
     */
    public function findCurrent(Uuid $planId, BillingPeriod $billingPeriod, Currency $currency): ?PlanPrice;

    public function save(PlanPrice $price): void;

    public function remove(PlanPrice $price): void;
}
