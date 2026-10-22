<?php

declare(strict_types=1);

namespace App\Api\Domain\Exception;

use App\Shared\Domain\Exception\InvalidArgumentException;

/**
 * La clave presentada no sirve: no existe, ha caducado o está revocada.
 *
 * Es una excepción de **dominio**, no un error HTTP. Quien la recibe decide
 * cómo presentarla: la API devuelve un `401` con un mensaje genérico (sin
 * distinguir entre «no existe» y «revocada», que sería ayudar a quien
 * prueba claves), y el panel dice exactamente qué pasa.
 */
final class InvalidApiKeyException extends InvalidArgumentException
{
    public static function notFound(): self
    {
        return new self('La clave de API no es válida.');
    }

    public static function expired(): self
    {
        return new self('La clave de API ha caducado.');
    }

    public static function revoked(): self
    {
        return new self('La clave de API ha sido revocada.');
    }
}
