<?php

declare(strict_types=1);

namespace App\Shared\Application\Entitlements;

use App\Shared\Domain\Exception\QuotaExceededException;

/**
 * Puerto que comprueba los límites del plan (Fase 17).
 *
 * ## Por qué un puerto y no la clase concreta
 *
 * `QuotaGuard` sabe cruzar el límite con el uso real, y para eso necesita
 * la configuración, los ajustes de la organización y los contadores de cada
 * módulo. Un caso de uso como `CreateService` no necesita nada de eso: solo
 * necesita que le digan si puede continuar.
 *
 * Depender de un puerto hace que el caso de uso se pueda probar sin montar
 * el plan entero, y deja abierta la puerta a que el límite se resuelva de
 * otra forma (una pasarela de pago, un contrato) sin tocar a quien consume.
 */
interface QuotaCheckerInterface
{
    /**
     * ¿El plan permite seguir usando esta capacidad?
     */
    public function allows(string $capability): bool;

    /**
     * Comprueba que la organización puede seguir usando esta capacidad.
     *
     * @throws QuotaExceededException cuando el plan ya no da para más
     */
    public function assertWithinQuota(string $capability): void;
}
