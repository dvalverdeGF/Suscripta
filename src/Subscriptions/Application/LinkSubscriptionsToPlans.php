<?php

declare(strict_types=1);

namespace App\Subscriptions\Application;

use App\Subscriptions\Domain\Entity\Plan;
use App\Subscriptions\Domain\Repository\PlanRepositoryInterface;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;

use function count;
use function mb_strtolower;
use function preg_replace;
use function str_replace;
use function trim;

/**
 * Enlaza con su plan las suscripciones que se crearon antes de que existiera el
 * catálogo (relleno que la fase 21 dejó pendiente).
 *
 * ## Por qué un comando y no una migración
 *
 * Una migración tendría que escribir los identificadores de los planes, y esos
 * los genera la base de datos cuando el catálogo se siembra. Un comando se
 * ejecuta **después** de sembrar, se puede volver a lanzar sin efectos (solo
 * toca las que siguen sin plan) y admite `--dry-run` para ver qué haría antes
 * de hacerlo.
 *
 * ## Cómo se empareja
 *
 * Por nombre: el de la suscripción contra el nombre comercial del plan, su
 * gancho y el nombre del plan de la aplicación. Se comparan normalizados (sin
 * mayúsculas, sin acentos, sin espacios de más) porque quien escribió «PRO» a
 * mano no quiso decir otra cosa que «Profesional».
 *
 * Si un nombre encaja con dos planes, **no se enlaza**: adivinar sería peor que
 * dejar la fila pendiente, y la ficha de la suscripción permite elegir el plan a
 * mano.
 */
final readonly class LinkSubscriptionsToPlans
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptions,
        private PlanRepositoryInterface $plans,
    ) {
    }

    /**
     * @return list<array{name: string, plan: string}> enlaces aplicados (o que se aplicarían)
     */
    public function __invoke(bool $dryRun = false): array
    {
        /** @var array<string, list<Plan>> $byName */
        $byName = [];

        foreach ($this->plans->findAll() as $plan) {
            foreach ($this->aliases($plan) as $alias) {
                $byName[$alias][] = $plan;
            }
        }

        $linked = [];

        foreach ($this->subscriptions->findWithoutPlan() as $subscription) {
            $key = self::normalize($subscription->getName());
            $candidates = $byName[$key] ?? [];

            if (1 !== count($candidates)) {
                continue;
            }

            $plan = $candidates[0];
            $linked[] = ['name' => $subscription->getName(), 'plan' => $plan->getName()];

            if ($dryRun) {
                continue;
            }

            // El nombre de la suscripción no se toca: está congelado desde el
            // alta (D-79), y enlazar un plan no lo renombra.
            $subscription->changePlan($plan->getId());
            $this->subscriptions->save($subscription);
        }

        return $linked;
    }

    /**
     * Formas en las que el operador puede haber escrito el nombre del plan.
     *
     * @return list<string>
     */
    private function aliases(Plan $plan): array
    {
        $aliases = [
            self::normalize($plan->getName()),
            self::normalize($plan->getCode()->label()),
            self::normalize($plan->getCode()->value),
        ];

        return array_values(array_unique(array_filter($aliases, static fn (string $alias): bool => '' !== $alias)));
    }

    /**
     * Minúsculas, sin acentos y con los espacios colapsados.
     */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ', 'à', 'è', 'ì', 'ò', 'ù', 'ç'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n', 'a', 'e', 'i', 'o', 'u', 'c'],
            $value,
        );

        return (string) preg_replace('/\s+/', ' ', $value);
    }
}
