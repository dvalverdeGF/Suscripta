<?php

declare(strict_types=1);

namespace App\Webhooks\Domain\Exception;

use RuntimeException;

use function sprintf;

/**
 * La URL de un suscriptor no es un destino admisible (Fase 20).
 *
 * No es un error de red ni un fallo del receptor: es una URL que Suscripta se
 * niega a llamar porque la petición saldría desde dentro de la infraestructura
 * y apuntaría a algo que no es un servicio público del cliente. Un
 * administrador de organización podría usarla para tantear la red interna, y
 * una cuenta robada, para alcanzar el endpoint de metadatos de la nube.
 */
final class UnsafeWebhookUrlException extends RuntimeException
{
    public static function notResolvable(string $host): self
    {
        return new self(sprintf('No se ha podido resolver el host «%s».', $host));
    }

    public static function privateTarget(string $host, string $ip): self
    {
        return new self(sprintf(
            'La URL apunta a una dirección interna (%s → %s). Un webhook tiene que ser un servicio público.',
            $host,
            $ip,
        ));
    }

    public static function unsupportedScheme(string $url): self
    {
        return new self(sprintf('Solo se admiten URLs https://, y esta no lo es: %s', $url));
    }

    public static function missingHost(string $url): self
    {
        return new self(sprintf('La URL no tiene host: %s', $url));
    }
}
