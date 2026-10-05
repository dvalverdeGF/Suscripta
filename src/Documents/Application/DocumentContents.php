<?php

declare(strict_types=1);

namespace App\Documents\Application;

use App\Documents\Domain\Entity\Document;

/**
 * Un documento y sus bytes, listos para enviarse como respuesta.
 *
 * Se devuelve junto para que el controlador no tenga que volver a consultar el
 * documento y arriesgarse a servir un binario que no corresponde a los
 * metadatos que acaba de leer.
 */
final readonly class DocumentContents
{
    public function __construct(
        public Document $document,
        public string $contents,
    ) {
    }
}
