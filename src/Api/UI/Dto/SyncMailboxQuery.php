<?php

declare(strict_types=1);

namespace App\Api\UI\Dto;

/**
 * Ajustes de una sincronización de buzón.
 *
 * `months` acota la ventana de correo que se mira y `limit` el número de
 * mensajes por pasada. Los dos tienen un valor por defecto razonable (el
 * que usa el panel), así que una integración puede llamar al endpoint sin
 * parameters.
 */
final readonly class SyncMailboxQuery
{
    public function __construct(
        public ?int $months = null,
        public ?int $limit = null,
    ) {
    }
}
