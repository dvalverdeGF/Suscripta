<?php

declare(strict_types=1);

namespace App\Billing\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Sesión de pago abierta en la pasarela (Fase 24).
 *
 * Solo interesan dos datos: el identificador —para poder consultarla después— y
 * la URL a la que se manda al cliente. El resto de la sesión (importe, cliente,
 * método de pago) es de la pasarela y se consulta si hace falta; copiarlo aquí
 * sería mantener una segunda copia que se desactualiza.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $externalId,
        public string $url,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
    }
}
