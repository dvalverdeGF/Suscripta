<?php

declare(strict_types=1);

namespace App\Ai\Application;

use App\Shared\Application\TenantContext;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limita cuántas peticiones de IA se hacen por minuto y organización (Fase 14).
 *
 * ## Por qué hace falta un límite además del presupuesto
 *
 * El presupuesto protege el **bolsillo**; este protege la **operación**:
 *
 * - El presupuesto va en céntimos y se comprueba contra el histórico. Un
 *   proveedor que devuelve un error en bucle puede generar mil llamadas
 *   fallidas, que ni siquiera cuestan dinero, pero sí consumen cuota y
 *   ralentizan la sincronización.
 * - Los proveedores de IA tienen sus propios límites de peticiones, y
 *   chocarse con ellos produce errores `429` que ensucian la traza.
 * - Un buzón con un correo que se repite (un mailing que rebota) puede pedir
 *   la misma extracción cientos de veces en una sincronización.
 *
 * ## Qué cuenta
 *
 * Cuenta **mensajes enviados a la escalera de IA**, no llamadas al
 * proveedor: un mensaje puede llegar a hacer dos llamadas (nivel 5 y nivel 6),
 * así que el tope de peticiones reales es el doble del de mensajes. Es una
 * decisión deliberada: lo que se quiere limitar es el trabajo, y el trabajo
 * se mide en mensajes.
 *
 * ## La clave es por organización
 *
 * Cada cliente tiene su propio cubo. Un cliente que satura el límite no
 * puede tumbar a los demás, que es justo lo que un límite global haría.
 */
final readonly class AiCallRateLimiter
{
    public function __construct(
        private RateLimiterFactoryInterface $limiter,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * Consume un hueco de peticiones.
     *
     * Devuelve `false` cuando ya no queda ninguno: el llamante debe dejar el
     * correo para revisión y reintentarlo más tarde.
     */
    public function acquire(): bool
    {
        $organizationId = $this->tenantContext->getOrganizationId();
        $key = $organizationId?->toRfc4122() ?? 'anonymous';

        return $this->limiter->create($key)->consume()->isAccepted();
    }
}
