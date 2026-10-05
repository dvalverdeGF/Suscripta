<?php

declare(strict_types=1);

namespace App\Processing\Application\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Petición de procesamiento de un mensaje ya almacenado.
 *
 * Se envía el identificador y no la entidad: el mensaje viaja por una cola y
 * puede procesarse minutos después, en otro proceso, cuando la entidad original
 * ya no existe en memoria. Cargarla de nuevo es lo único correcto.
 */
final readonly class ProcessEmailMessageMessage
{
    public function __construct(
        public Uuid $emailMessageId,
    ) {
    }
}
