<?php

declare(strict_types=1);

namespace App\Ai\Application;

/**
 * Reacción del resto del sistema a un cambio del presupuesto de IA (Fase 14).
 *
 * ## Por qué una interfaz y no una llamada directa
 *
 * Cuando el cliente amplía el presupuesto hay que devolver al pipeline lo
 * que quedó en espera por falta de presupuesto. Ese «algo que quedó en
 * espera» vive en el módulo de `Processing`, no en el de `Ai`: el módulo de IA
 * sabe cuánto se ha gastado, pero no sabe qué se quedó sin procesar.
 *
 * La dependencia documentada es `Processing → Ai` (el pipeline usa la
 * escalera de IA), no al revés. Si el controlador de IA llamara directamente
 * al servicio de `Processing`, estaríamos creando un ciclo entre los dos
 * módulos y ninguno de los dos necesita conoscerse.
 *
 * ## La forma de la solución
 *
 * El módulo de IA **declara** lo que espera que pase (un puerto) y el módulo
 * de `Processing` lo **implementa** (un adaptador). El interfaz la resuelve el
 * contenedor, no el código de negocio.
 *
 * El nombre es deliberadamente abstracto: este módulo no sabe qué se libera,
 * solo sabe que al subir el presupuesto hay algo que se ha quedado en el
 * camino y que hay que devolver.
 */
interface AiBudgetChangeHandlerInterface
{
    /**
     * Se ha ampliado el presupuesto de IA.
     *
     * @return int cuántos elementos quedaban en espera y se han devuelto
     */
    public function onBudgetRaised(): int;
}
