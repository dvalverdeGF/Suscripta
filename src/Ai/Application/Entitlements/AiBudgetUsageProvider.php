<?php

declare(strict_types=1);

namespace App\Ai\Application\Entitlements;

use App\Ai\Application\AiBudgetGuard;
use App\Shared\Application\Entitlements\Capability;
use App\Shared\Application\Entitlements\EntitlementUsageProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Uso de la capacidad «presupuesto de IA» (Fase 17).
 *
 * El presupuesto de IA se cuenta en céntimos gastados, no en elementos, así
 * que es la única capacidad cuyo uso **no** es un número de cosas, sino una
 * cantidad de dinero. El resto del sistema no necesita saberlo: lo que
 * necesita es que alguien publica ese número, y este es ese alguien.
 *
 * El atributo `#[AsTaggedItem]` dice bajo qué clave se guarda este proveedor
 * en el localizador de cuotas (D-70).
 */
#[AsTaggedItem(Capability::AI_BUDGET_MINOR)]
final readonly class AiBudgetUsageProvider implements EntitlementUsageProviderInterface
{
    public function __construct(
        private AiBudgetGuard $guard,
    ) {
    }

    public function capability(): string
    {
        return Capability::AI_BUDGET_MINOR;
    }

    public function usage(): int
    {
        return $this->guard->spentMinor();
    }
}
