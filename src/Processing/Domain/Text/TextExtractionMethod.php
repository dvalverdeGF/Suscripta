<?php

declare(strict_types=1);

namespace App\Processing\Domain\Text;

/**
 * Cómo se obtuvo el texto de un documento.
 *
 * Se guarda en el log de procesamiento porque es la diferencia entre un coste
 * cero y un coste real: `TEXT` es una librería local, `OCR` es CPU propia y
 * `NONE` significa que el documento no aportó nada y el pipeline tendrá que
 * decidir con lo que venía en el correo.
 */
enum TextExtractionMethod: string
{
    /** Capa de texto del propio documento. */
    case TEXT = 'text';

    /** Reconocimiento óptico sobre una imagen. */
    case OCR = 'ocr';

    /** No se pudo obtener texto. */
    case NONE = 'none';

    public function label(): string
    {
        return match ($this) {
            self::TEXT => 'Texto del documento',
            self::OCR => 'Reconocimiento óptico',
            self::NONE => 'Sin texto',
        };
    }

    public function isLocal(): bool
    {
        return self::NONE !== $this;
    }
}
