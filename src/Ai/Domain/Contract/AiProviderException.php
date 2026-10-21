<?php

declare(strict_types=1);

namespace App\Ai\Domain\Contract;

use RuntimeException;

use function sprintf;

/**
 * Fallo del proveedor de IA.
 *
 * Es una excepción de dominio y no de infraestructura porque quien la captura
 * —el proceso de extracción— solo necesita saber que **la IA no pudo
 * responder**, no por qué falló la red. El motivo concreto se guarda en la
 * traza, no en el mensaje de la excepción que puede acabar en un log de
 * usuario.
 */
final class AiProviderException extends RuntimeException
{
    public static function notConfigured(string $key, string $reason = ''): self
    {
        $message = sprintf('El proveedor de IA "%s" no está configurado.', $key);

        if ('' !== $reason) {
            $message .= ' '.$reason;
        }

        return new self($message);
    }

    public static function notAvailableForPurpose(string $purpose): self
    {
        return new self(sprintf('No hay ningún proveedor de IA configurado para la necesidad "%s".', $purpose));
    }

    public static function noAdvancedProvider(): self
    {
        return new self('No hay ningún proveedor de IA avanzado configurado.');
    }

    public static function requestFailed(string $key, string $reason): self
    {
        return new self(sprintf('El proveedor de IA "%s" falló: %s', $key, $reason));
    }

    public static function invalidResponse(string $key, string $reason = ''): self
    {
        $message = sprintf('El proveedor de IA "%s" devolvió una respuesta que no se puede usar.', $key);

        if ('' !== $reason) {
            $message .= ' '.$reason;
        }

        return new self($message);
    }
}
