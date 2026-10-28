<?php

declare(strict_types=1);

namespace App\Billing\Domain\Exception;

use RuntimeException;

/**
 * La pasarela de pago no está configurada (Fase 24).
 *
 * Se lanza al intentar abrir un checkout sin clave secreta guardada, y el
 * controlador la convierte en un aviso en la web. No es un fallo del programa:
 * es un despliegue a medio configurar, y decirlo con claridad ahorra una tarde
 * de buscar un 500 sin mensaje.
 */
final class GatewayNotConfiguredException extends RuntimeException
{
    public static function missingSecretKey(): self
    {
        return new self('La pasarela de pago no está configurada: falta la clave secreta en el panel de integraciones.');
    }

    public static function missingWebhookSecret(): self
    {
        return new self('La pasarela de pago no puede verificar webhooks: falta el secreto de firma en el panel de integraciones.');
    }
}
