<?php

declare(strict_types=1);

namespace App\Billing\Domain\Exception;

use RuntimeException;

use function sprintf;

/**
 * La pasarela ha respondido algo que no es un éxito (Fase 24).
 *
 * Guarda el código HTTP y **no** el cuerpo completo: el cuerpo de un error de
 * Stripe puede incluir datos del cliente, y un log es el peor sitio donde
 * dejarlos. Se conserva el mensaje corto que la pasarela da para el operador.
 */
final class GatewayRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
    ) {
        parent::__construct($message);
    }

    public static function failed(string $operation, int $statusCode, ?string $detail = null): self
    {
        $message = sprintf('La pasarela ha rechazado «%s» (HTTP %d)', $operation, $statusCode);

        return new self(null === $detail || '' === $detail ? $message.'.' : $message.': '.$detail, $statusCode);
    }

    public static function transport(string $operation, string $detail): self
    {
        return new self(sprintf('No se ha podido hablar con la pasarela en «%s»: %s', $operation, $detail));
    }

    /**
     * Si el fallo es del cliente (4xx) y reintentar no va a cambiarlo.
     *
     * Sirve para decidir si un evento se marca como fallido definitivo o se
     * deja pendiente para el siguiente reintento de la pasarela.
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500 && 429 !== $this->statusCode;
    }
}
