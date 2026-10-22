<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Entitlements;

use App\Shared\Application\Entitlements\EntitlementCheckerInterface;
use App\Shared\Application\Entitlements\EntitlementSettings;
use App\Shared\Application\OrganizationSettingsReader;

/**
 * Resuelve los límites del plan leyendo la configuración (Fase 17).
 *
 * No guarda estado: la configuración es la única fuente de la verdad y se
 * lee en cada llamada. Es lo que permite cambiar un límite sin desplegar
 * nada, y lo que hace que dos peticiones concurrentes nunca vean valores
 * distintos.
 */
final readonly class PlanEntitlementChecker implements EntitlementCheckerInterface
{
    public function __construct(
        private EntitlementSettings $settings,
        private OrganizationSettingsReader $organizations,
    ) {
    }

    public function limit(string $capability): ?int
    {
        $organization = $this->organizations->current();

        if (null === $organization) {
            return null;
        }

        return $this->settings->limitFor($organization->getPlan(), $capability);
    }

    public function allows(string $capability): bool
    {
        $organization = $this->organizations->current();

        if (null === $organization) {
            return true;
        }

        return $this->settings->allows($organization->getPlan(), $capability);
    }
}
