<?php

declare(strict_types=1);

namespace App\Website\Application;

use App\Identity\Domain\Enum\OrganizationPlan;
use App\Shared\Application\Entitlements\EntitlementSettings;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Subscriptions\Domain\Entity\PlanPrice;
use App\Subscriptions\Domain\Repository\PlanFeatureRepositoryInterface;
use App\Subscriptions\Domain\Repository\PlanPriceRepositoryInterface;
use App\Subscriptions\Domain\Repository\PlanRepositoryInterface;

use function in_array;
use function is_bool;
use function round;
use function sprintf;

/**
 * Monta la página de precios a partir del catálogo comercial (Fase 22).
 *
 * **Los precios no se escriben en la plantilla.** Se leen de `PlanPrice` en su
 * versión vigente, así que un cambio de precio en el backoffice aparece en la
 * web sin desplegar: es exactamente lo que la fase 22 vino a permitir.
 *
 * Los **límites** se leen de `entitlements.yaml` y no de la base de datos,
 * porque son los que el producto aplica de verdad (D-64). Si la web prometiera
 * unos límites distintos de los que el guard de cuotas hace cumplir, la
 * primera queja de un cliente tendría razón.
 */
final readonly class BuildPricingPage
{
    /**
     * Capacidades que **no** se publican.
     *
     * `ai_budget_minor` es un tope interno de coste, no algo que se venda: en la
     * web se dice «IA incluida» y lo que cuesta cada llamada es un problema
     * nuestro. Enseñarlo en céntimos, además, se lee como un error.
     */
    private const array HIDDEN_CAPABILITIES = ['ai_budget_minor'];

    public function __construct(
        private PlanRepositoryInterface $plans,
        private PlanPriceRepositoryInterface $prices,
        private PlanFeatureRepositoryInterface $features,
        private EntitlementSettings $entitlements,
    ) {
    }

    /** @return list<PlanCard> */
    public function __invoke(): array
    {
        $cards = [];

        foreach ($this->plans->findAll(onlyActive: true) as $plan) {
            $monthly = null;
            $quarterly = null;
            $annual = null;

            foreach ($this->prices->findCurrentForPlan($plan->getId()) as $price) {
                match ($price->getBillingPeriod()) {
                    BillingPeriod::MONTHLY => $monthly = $price,
                    BillingPeriod::QUARTERLY => $quarterly = $price,
                    BillingPeriod::ANNUAL => $annual = $price,
                    default => null,
                };
            }

            $features = [];
            foreach ($this->features->findForPlan($plan->getId()) as $feature) {
                $features[] = ['label' => $feature->getLabel(), 'included' => $feature->isIncluded()];
            }

            $cards[] = new PlanCard(
                name: $plan->getName(),
                tagline: $plan->getTagline(),
                description: $plan->getDescription(),
                highlighted: $plan->isHighlighted(),
                monthly: $monthly,
                quarterly: $quarterly,
                annual: $annual,
                annualSavingPercent: $this->savingPercent($monthly, $annual),
                features: $features,
                limits: $this->limits($plan->getCode()),
            );
        }

        return $cards;
    }

    /**
     * Cuánto se ahorra pagando el año entero, en porcentaje entero.
     *
     * Se calcula y no se escribe: si mañana el precio anual cambia y la rebaja
     * no, la web mentiría. Devuelve `null` cuando no hay con qué comparar o
     * cuando el anual no es más barato que doce meses sueltos.
     */
    private function savingPercent(?PlanPrice $monthly, ?PlanPrice $annual): ?int
    {
        if (null === $monthly || null === $annual || $monthly->getAmount()->isZero()) {
            return null;
        }

        $twelveMonths = $monthly->getAmount()->multiply(12)->amountMinor;
        $yearly = $annual->getAmount()->amountMinor;

        if ($twelveMonths <= $yearly) {
            return null;
        }

        $percent = (int) round((($twelveMonths - $yearly) / $twelveMonths) * 100);

        return $percent > 0 ? $percent : null;
    }

    /**
     * Los límites del plan, ya en texto.
     *
     * @return list<array{label: string, value: string}>
     */
    private function limits(OrganizationPlan $code): array
    {
        $limits = [];

        foreach ($this->entitlements->capabilityNames() as $capability) {
            if (in_array($capability, self::HIDDEN_CAPABILITIES, true)) {
                continue;
            }

            $value = $this->entitlements->valueFor($code, $capability);
            $label = $this->entitlements->labelFor($capability);

            $limits[] = ['label' => $label, 'value' => $this->describe($value, $capability)];
        }

        return $limits;
    }

    /**
     * Un límite en una frase corta.
     *
     * `null` en un límite significa «sin techo» y `0` significa «no está
     * incluido»: son dos cosas distintas y decir «0» en los dos casos sería
     * una web que no explica el producto.
     */
    private function describe(int|bool|null $value, string $capability): string
    {
        if (is_bool($value)) {
            return $value ? 'Incluida' : 'No incluida';
        }

        if (null === $value) {
            return 'Sin límite';
        }

        if (0 === $value) {
            return 'No incluida';
        }

        $unit = $this->entitlements->unitFor($capability);

        return null === $unit ? sprintf('Hasta %d', $value) : sprintf('Hasta %d %s', $value, $unit);
    }
}
