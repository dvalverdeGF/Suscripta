<?php

declare(strict_types=1);

namespace App\Shared\Application\Entitlements;

/**
 * Consulta qué permite el plan de la organización activa (Fase 17).
 *
 * ## Qué decide y qué no
 *
 * Decide **qué ofrece el plan**: cuántos servicios puede tener, si puede usar
 * inteligencia artificial, cuál es su tope de presupuesto. No decide si la
 * organización ha llegado ya a ese límite: para eso hace falta saber cuántas
 * unidades usa, y ese dato lo tiene cada módulo (cuántos servicios, cuántos
 * buzones, cuántos documentos). Por eso el uso va aparte, en
 * `EntitlementUsageProviderInterface`, y lo cruza `QuotaGuard`.
 *
 * ## Por qué la organización activa y no un identificador
 *
 * Los límites se aplican a la organización con la que se está trabajando, que
 * es siempre la activa: la de la sesión, la de la clave de API o la que el
 * worker ha fijado en el contexto. Preguntar por un identificador obligaría a
 * que cada consumidor resolviera la organización por su cuenta, y a que
 * alguien pudiera comprobar el plan de una organización que no es la suya.
 */
interface EntitlementCheckerInterface
{
    /**
     * Límite numérico de una capacidad (`null` = sin límite).
     *
     * Para una capacidad de tipo interruptor (como el uso de IA) el valor es
     * `null`: no es un número.
     */
    public function limit(string $capability): ?int;

    /**
     * ¿El plan ofrece esta capacidad?
     *
     * Para un límite numérico, `true` cuando el límite es `null` (ilimitado)
     * o mayor que cero. Para un interruptor, `true` cuando está activo.
     *
     * Esto **no** dice si la organización ha agotado el límite: de eso se
     * encarga `QuotaGuard`, que necesita el uso.
     */
    public function allows(string $capability): bool;
}
