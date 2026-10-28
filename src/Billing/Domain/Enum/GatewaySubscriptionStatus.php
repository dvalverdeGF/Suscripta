<?php

declare(strict_types=1);

namespace App\Billing\Domain\Enum;

use App\Subscriptions\Domain\Enum\SubscriptionStatus;

use function strtolower;
use function trim;

/**
 * Estado de una suscripción **en la pasarela** (Fase 24).
 *
 * ## Por qué no se reutiliza `SubscriptionStatus`
 *
 * Los dos enumerados hablan de lo mismo visto desde sitios distintos. Stripe
 * tiene ocho estados y Suscripta cinco, y la traducción **no es uno a uno**: un
 * `unpaid` de Stripe es un `past_due` nuestro (el cliente no ha pagado y hay
 * que seguir dando servicio mientras se le reclama), y `incomplete` no es
 * ninguno de los nuestros porque una suscripción que nunca se cobró no llega a
 * existir en Suscripta.
 *
 * Si se usara el mismo enumerado para las dos cosas, cada valor nuevo de la
 * pasarela obligaría a tocar nuestro ciclo de vida, y al revés.
 *
 * ## Lo que no se mapea, no se toca
 *
 * `toSubscriptionStatus()` devuelve `null` para lo que no tiene traducción
 * sensata. Devolver `null` es una instrucción explícita: «no cambies el estado
 * local por este evento». Es más seguro que elegir un estado por parecido.
 */
enum GatewaySubscriptionStatus: string
{
    case ACTIVE = 'active';
    case TRIALING = 'trialing';
    case PAST_DUE = 'past_due';
    case UNPAID = 'unpaid';
    case PAUSED = 'paused';
    case CANCELED = 'canceled';
    case INCOMPLETE = 'incomplete';
    case INCOMPLETE_EXPIRED = 'incomplete_expired';

    /**
     * Traduce el estado de la pasarela, o `null` si es uno que no conocemos.
     *
     * Un estado desconocido no es un error: Stripe añade estados con el
     * tiempo y una versión vieja del código no puede saber qué significan.
     */
    public static function tryFromGateway(?string $value): ?self
    {
        if (null === $value) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    /**
     * Estado local equivalente, o `null` para «no toques nada».
     */
    public function toSubscriptionStatus(): ?SubscriptionStatus
    {
        return match ($this) {
            self::ACTIVE => SubscriptionStatus::ACTIVE,
            self::TRIALING => SubscriptionStatus::TRIALING,
            self::PAST_DUE, self::UNPAID => SubscriptionStatus::PAST_DUE,
            self::PAUSED => SubscriptionStatus::PAUSED,
            self::CANCELED, self::INCOMPLETE_EXPIRED => SubscriptionStatus::CANCELED,
            // Una suscripción `incomplete` es un checkout que no llegó a
            // cobrarse: no hay contrato que reflejar.
            self::INCOMPLETE => null,
        };
    }
}
