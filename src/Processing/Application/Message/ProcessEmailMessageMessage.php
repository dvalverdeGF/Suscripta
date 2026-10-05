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
    /**
     * Cuerpo del mensaje, solo cuando la vía de ingesta lo trae consigo.
     *
     * Los correos reenviados (D-21) no están en ningún buzón del que
     * descargarlos, así que el cuerpo viaja con la petición. Es un dato de paso
     * por la cola, no un almacén: se descarta al procesar (D-10).
     */
    public function __construct(
        public Uuid $emailMessageId,
        public ?string $body = null,
    ) {
    }
}
