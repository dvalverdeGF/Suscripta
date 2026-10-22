<?php

declare(strict_types=1);

namespace App\Tests\Support\Entitlements;

use App\Shared\Application\Entitlements\EntitlementCheckerInterface;

/**
 * Doble de los límites de plan para las pruebas (Fase 17).
 *
 * ## Por qué no el checker de verdad
 *
 * El checker real lee la configuración y la organización activa. En una
 * prueba unitaria eso significa montar un contenedor entero para poder
 * comprobar que un presupuesto no se pasa. Aquí basta con decir qué límites
 * hay.
 *
 * ## Semántica
 *
 * Misma que la de `entitlements.yaml`:
 *
 * - capacidad no configurada → sin techo (`null`) y permitida.
 * - límite `0` → la capacidad no está disponible en el plan.
 */
final class InMemoryEntitlementChecker implements EntitlementCheckerInterface
{
    /**
     * @param array<string, int> $limits límite por capacidad; `0` = no disponible
     */
    public function __construct(
        private readonly array $limits = [],
    ) {
    }

    /**
     * Un plan sin ningún límite: todo permitido.
     */
    public static function allowingEverything(): self
    {
        return new self();
    }

    /**
     * Un plan con un límite por capacidad.
     *
     * @param array<string, int> $limits
     */
    public static function withLimits(array $limits): self
    {
        return new self($limits);
    }

    public function limit(string $capability): ?int
    {
        return $this->limits[$capability] ?? null;
    }

    public function allows(string $capability): bool
    {
        $limit = $this->limit($capability);

        return null === $limit || $limit > 0;
    }
}
