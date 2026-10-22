<?php

declare(strict_types=1);

namespace App\Services\Application\Entitlements;

use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Entitlements\Capability;
use App\Shared\Application\Entitlements\EntitlementUsageProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Cuántos servicios tiene la organización activa (Fase 17).
 *
 * El recuento lo hace el repositorio, que ya filtra por la organización
 * activa: el proveedor no necesita saber nada del aislamiento por tenant.
 *
 * El atributo `#[AsTaggedItem]` dice bajo qué clave se guarda este proveedor
 * en el localizador de cuotas. Sin él, el contenedor indexaría el proveedor
 * por el nombre de su clase y el guard no encontraría nada (D-70).
 */
#[AsTaggedItem(Capability::SERVICES)]
final readonly class ServiceUsageProvider implements EntitlementUsageProviderInterface
{
    public function __construct(
        private ServiceRepositoryInterface $services,
    ) {
    }

    public function capability(): string
    {
        return Capability::SERVICES;
    }

    public function usage(): int
    {
        return $this->services->countAll();
    }
}
