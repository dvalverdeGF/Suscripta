<?php

declare(strict_types=1);

namespace App\Website\Application;

use App\Subscriptions\Domain\Entity\PlanPrice;
use Symfony\Component\Uid\Uuid;

/**
 * Una tarjeta de la página de precios, ya resuelta.
 *
 * Es un objeto de **presentación**, no de dominio: mezcla el catálogo comercial
 * (nombre, gancho, precios, características) con los límites de
 * `entitlements.yaml`, que son la única fuente de lo que el plan permite
 * (D-64). La plantilla no consulta nada: si tuviera que hacerlo, la misma
 * lógica estaría escrita en Twig y sin test.
 */
final readonly class PlanCard
{
    /**
     * @param list<array{label: string, included: bool}>                                  $features
     * @param list<array{label: string, value: string}>                                   $limits
     * @param list<array{priceId: string, period: string, label: string, amount: string}> $purchases
     */
    public function __construct(
        public Uuid $planId,
        public string $name,
        public ?string $tagline,
        public ?string $description,
        public bool $highlighted,
        public ?PlanPrice $monthly,
        public ?PlanPrice $quarterly,
        public ?PlanPrice $annual,
        public ?int $annualSavingPercent,
        public array $features,
        public array $limits,
        /**
         * Los precios que se pueden contratar desde la web, ya en texto.
         *
         * Va aparte de `monthly`/`quarterly`/`annual` porque una tarjeta puede
         * enseñar el precio mensual y ofrecer además el anual: son dos cosas
         * distintas —qué se destaca y qué se puede comprar— y mezclarlas haría
         * que la tarjeta solo dejara contratar lo que enseña en grande.
         */
        public array $purchases = [],
    ) {
    }

    /**
     * El precio que se enseña en grande.
     *
     * Si no hay precio mensual se usa el anual, porque un plan sin ningún
     * precio que enseñar no debería estar publicado; y si tampoco lo hay, la
     * plantilla dice «A consultar» en lugar de inventarse un cero.
     */
    public function headline(): ?PlanPrice
    {
        return $this->monthly ?? $this->annual ?? $this->quarterly;
    }

    public function isFree(): bool
    {
        $headline = $this->headline();

        return null !== $headline && $headline->getAmount()->isZero();
    }
}
