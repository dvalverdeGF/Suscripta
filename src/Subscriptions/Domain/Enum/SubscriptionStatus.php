<?php

declare(strict_types=1);

namespace App\Subscriptions\Domain\Enum;

/**
 * Estado de una suscripción del catálogo.
 *
 * No se reutiliza `ServiceStatus` a propósito: son dos ciclos de vida
 * distintos. Un servicio detectado en una factura está «activo» porque se está
 * cobrando; una suscripción está «activa» porque el cobro ha sido correcto.
 * `PAST_DUE` es la prueba de que no se pueden unificar: es un estado de cobro
 * que un servicio descubierto en el correo nunca tendrá (D-78).
 */
enum SubscriptionStatus: string
{
    case TRIALING = 'trialing';
    case ACTIVE = 'active';
    case PAST_DUE = 'past_due';
    case PAUSED = 'paused';
    case CANCELED = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::TRIALING => 'En prueba',
            self::ACTIVE => 'Activa',
            self::PAST_DUE => 'Impagada',
            self::PAUSED => 'En pausa',
            self::CANCELED => 'Cancelada',
        };
    }

    /**
     * Si la suscripción está viva: ocupa una plaza del plan y cuenta para los
     * ingresos recurrentes. `PAST_DUE` cuenta —el cliente la tiene, solo que no
     * ha pagado— y `PAUSED` no: no se está cobrando nada.
     */
    public function isLive(): bool
    {
        return match ($this) {
            self::TRIALING, self::ACTIVE, self::PAST_DUE => true,
            self::PAUSED, self::CANCELED => false,
        };
    }

    /**
     * Si el cobro está al día. Es lo que decide si se puede renovar el periodo.
     */
    public function isPaid(): bool
    {
        return self::ACTIVE === $this;
    }

    public function isFinal(): bool
    {
        return self::CANCELED === $this;
    }

    public function badge(): string
    {
        return match ($this) {
            self::TRIALING => 'info',
            self::ACTIVE => 'success',
            self::PAST_DUE => 'danger',
            self::PAUSED => 'warning',
            self::CANCELED => 'secondary',
        };
    }
}
