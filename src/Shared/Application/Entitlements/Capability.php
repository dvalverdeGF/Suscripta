<?php

declare(strict_types=1);

namespace App\Shared\Application\Entitlements;

/**
 * Capacidades que un plan puede limitar.
 *
 * Los nombres son **cadenas y no constantes de clase** porque cruzan la
 * frontera del contenedor: los proveedores de uso (los módulos que saben
 * contar) se localizan por etiqueta con el nombre de la capacidad, igual que
 * los limitadores de frecuencia. Una constante de PHP no viajaría de un
 * módulo a otro sin que uno de los dos dependa del otro.
 *
 * La lista corta y su tipo (límite numérico o interruptor) viven en
 * `config/packages/entitlements.yaml`.
 */
final class Capability
{
    /** Servicios en el inventario. */
    public const SERVICES = 'services';

    /** Buzones de correo conectados. */
    public const EMAIL_ACCOUNTS = 'email_accounts';

    /** Documentos almacenados. */
    public const DOCUMENTS = 'documents';

    /** Disponibilidad de inteligencia artificial. */
    public const AI = 'ai';

    /** Tope mensual de presupuesto de IA, en céntimos. */
    public const AI_BUDGET_MINOR = 'ai_budget_minor';

    private function __construct()
    {
    }
}
