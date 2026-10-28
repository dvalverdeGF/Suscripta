<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Subscriptions\Domain\Entity\PlanPrice;
use RuntimeException;

use function sprintf;

/**
 * El precio elegido no se puede cobrar (Fase 24).
 *
 * Son casos **esperables**, no fallos: alguien abre una URL vieja, un plan
 * retirado o un plan gratuito, o ya tiene contratado eso mismo. Por eso llevan
 * un mensaje en castellano listo para enseñar y el controlador responde con un
 * aviso en la web, no con un 500.
 */
final class CheckoutUnavailableException extends RuntimeException
{
    public static function priceNotCurrent(PlanPrice $price): self
    {
        return new self(sprintf('El precio de «%s» que has elegido ya no está en vigor. Mira los precios actuales.', $price->getPlan()->getName()));
    }

    public static function planRetired(string $planName): self
    {
        return new self(sprintf('El plan «%s» ya no se ofrece.', $planName));
    }

    public static function freePlan(string $planName): self
    {
        return new self(sprintf('El plan «%s» es gratuito: no hay nada que pagar, basta con crear la cuenta.', $planName));
    }

    public static function alreadySubscribed(string $planName): self
    {
        return new self(sprintf('Ya tienes una suscripción activa al plan «%s». Puedes gestionarla desde el panel de suscripciones.', $planName));
    }

    public static function notFound(): self
    {
        return new self('El precio que has elegido no existe.');
    }
}
