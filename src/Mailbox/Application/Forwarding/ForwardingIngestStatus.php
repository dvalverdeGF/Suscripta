<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Forwarding;

/**
 * Resultado de intentar aceptar un correo reenviado (SECURITY.md §2.4).
 *
 * Se distinguen los motivos de rechazo porque cada uno se registra y se trata
 * de forma distinta: un remitente no autorizado es un intento de inyección,
 * mientras que un duplicado es simplemente el mismo correo llegando dos veces.
 */
enum ForwardingIngestStatus: string
{
    case ACCEPTED = 'accepted';
    case DUPLICATE = 'duplicate';
    case UNKNOWN_RECIPIENT = 'unknown_recipient';
    case FORWARDING_DISABLED = 'forwarding_disabled';
    case UNAUTHORIZED_SENDER = 'unauthorized_sender';
    case TOO_LARGE = 'too_large';
    case RATE_LIMITED = 'rate_limited';

    public function isAccepted(): bool
    {
        return self::ACCEPTED === $this;
    }

    /**
     * ¿Merece quedar registrado en la auditoría?
     *
     * Un duplicado no: es el comportamiento normal de un reenvío. Un remitente
     * no autorizado sí, porque puede ser un intento de meter contenido falso.
     */
    public function isSuspicious(): bool
    {
        return match ($this) {
            self::UNAUTHORIZED_SENDER, self::TOO_LARGE, self::RATE_LIMITED => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ACCEPTED => 'Aceptado',
            self::DUPLICATE => 'Ya lo teníamos',
            self::UNKNOWN_RECIPIENT => 'Dirección desconocida',
            self::FORWARDING_DISABLED => 'Reenvío desactivado',
            self::UNAUTHORIZED_SENDER => 'Remitente no autorizado',
            self::TOO_LARGE => 'Demasiado grande',
            self::RATE_LIMITED => 'Demasiados correos seguidos',
        };
    }
}
