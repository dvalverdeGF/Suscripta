<?php

declare(strict_types=1);

namespace App\Webhooks\Application;

/**
 * Destino validado de una entrega (Fase 20).
 *
 * Lleva el host y **la dirección concreta** a la que se ha resuelto. Que sean
 * dos datos y no uno es el punto: el cliente HTTP recibe la dirección fijada
 * (`resolve`) y no vuelve a preguntar al DNS, así que una respuesta distinta
 * entre la validación y la conexión —el truco del DNS rebinding— no tiene por
 * dónde colarse.
 */
final readonly class WebhookTarget
{
    public function __construct(
        public string $host,
        public string $ip,
    ) {
    }
}
