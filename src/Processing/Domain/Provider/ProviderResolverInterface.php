<?php

declare(strict_types=1);

namespace App\Processing\Domain\Provider;

use App\Mailbox\Application\Imap\ImapMessageHeader;

/**
 * Reconoce al proveedor de un correo (ARCHITECTURE.md §13.7).
 *
 * Es un puerto porque la escalera de resolución va a crecer: hoy son identidades
 * del catálogo, mañana serán también patrones de asunto, patrones de adjunto y
 * conocimiento aprendido del propio buzón (D-33). El pipeline no debe cambiar
 * cada vez que se añade un escalón.
 */
interface ProviderResolverInterface
{
    /**
     * Resuelve el proveedor a partir de los metadatos del mensaje.
     *
     * Nunca devuelve `null`: un proveedor desconocido es un resultado válido y
     * frecuente (la primera factura de cualquier proveedor nuevo), y el
     * pipeline necesita distinguir "no lo conozco" de "no he podido mirarlo".
     */
    public function resolve(ImapMessageHeader $header): ProviderMatch;
}
