<?php

declare(strict_types=1);

namespace App\Billing\Application;

/**
 * Qué ha pasado con un evento de la pasarela (Fase 24).
 *
 * Tres resultados posibles y ninguno es un error: **aplicado**, **duplicado**
 * (ya estaba) e **ignorado** (no se entiende o no se puede aplicar). Los tres
 * responden 200. La diferencia está en el log y en el panel de eventos, que es
 * donde se mira cuando un cobro «no se ha reflejado».
 */
enum PaymentEventOutcome: string
{
    case APPLIED = 'applied';
    case DUPLICATE = 'duplicate';
    case IGNORED = 'ignored';
}
