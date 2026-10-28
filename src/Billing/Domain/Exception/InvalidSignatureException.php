<?php

declare(strict_types=1);

namespace App\Billing\Domain\Exception;

use function abs;

use RuntimeException;

use function sprintf;

/**
 * La firma del webhook no es válida (Fase 24).
 *
 * Se lanza antes de tocar la base de datos y el controlador responde **400**.
 * Es la única defensa del endpoint público: sin firma válida, cualquiera podría
 * publicar `{"type":"invoice.paid"}` y activar planes gratis. Devolver 200
 * «para no dar pistas» sería peor: la pasarela dejaría de reintentar un evento
 * legítimo que llegó con la firma mal calculada.
 */
final class InvalidSignatureException extends RuntimeException
{
    public static function malformed(?string $reason = null): self
    {
        $message = 'La cabecera de firma no tiene el formato esperado.';

        return new self(null === $reason || '' === $reason ? $message : $message.' '.ucfirst($reason).'.');
    }

    public static function stale(int $seconds, int $tolerance): self
    {
        return new self(sprintf('La firma tiene %d segundos y la tolerancia es de %d.', abs($seconds), $tolerance));
    }

    public static function mismatch(): self
    {
        return new self('La firma no corresponde al cuerpo recibido.');
    }
}
