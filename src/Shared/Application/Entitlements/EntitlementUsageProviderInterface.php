<?php

declare(strict_types=1);

namespace App\Shared\Application\Entitlements;

/**
 * Cuántas unidades de una capacidad usa la organización activa (Fase 17).
 *
 * ## El patrón
 *
 * Cada módulo publica **su propio** contador, y el guard lo busca por
 * capacidad. Es el mismo patrón que los puertos de privacidad (§15): el
 * consumidor declara el puerto y el proveedor lo implementa, de modo que
 * `Shared` no conoce ningún módulo de negocio y añadir un límite nuevo es
 * escribir una clase, no tocar el núcleo.
 *
 * ## El alcance
 *
 * El recuento es **de la organización activa** (la del contexto de tenant),
 * igual que los `countForOrganization()` de los repositorios. Los comandos y
 * los workers que trabajan con una organización concreta la fijan en el
 * contexto antes de operar.
 */
interface EntitlementUsageProviderInterface
{
    /**
     * Capacidad que mide este proveedor.
     *
     * Debe coincidir con una de las constantes de `Capability` y con la
     * declarada en `config/packages/entitlements.yaml`.
     */
    public function capability(): string;

    /**
     * Cuántas unidades usa ahora mismo la organización activa.
     */
    public function usage(): int;
}
