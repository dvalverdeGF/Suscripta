<?php

declare(strict_types=1);

namespace App\Webhooks\Application;

use function rtrim;
use function strtr;

/**
 * Genera el secreto de firma de un suscriptor (Fase 20).
 *
 * 32 bytes de azar criptográfico en base64url: 256 bits, que es el tamaño
 * natural de la clave de un HMAC-SHA-256 (más bits no añaden nada, menos
 * acortan la clave). Se prefiere base64url a base64 normal porque el secreto
 * acaba en una variable de entorno o en un `Authorization` y los `+` y `/` se
 * escapan mal en la mitad de las herramientas.
 *
 * El prefijo `whsec_` no aporta seguridad: sirve para que un secreto de webhook
 * se reconozca de un vistazo en un fichero de configuración y no se confunda
 * con una clave de API (`sk_`) ni con una contraseña.
 */
final readonly class WebhookSecretGenerator
{
    public function generate(): string
    {
        return 'whsec_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
