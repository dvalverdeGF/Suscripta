<?php

declare(strict_types=1);

namespace App\Processing\Infrastructure\Ocr;

use App\Processing\Domain\Ocr\OcrEngineInterface;

/**
 * Motor de OCR ausente (ARCHITECTURE.md §13.6).
 *
 * Se registra cuando no hay Tesseract instalado. No es un adorno: el pipeline
 * consulta `isAvailable()` antes de intentar nada, así que un entorno sin OCR
 * degrada a IA o a revisión en lugar de fallar. Es la diferencia entre "esta
 * instalación no reconoce imágenes" y "esta instalación se rompe con las
 * imágenes".
 */
final readonly class NullOcrEngine implements OcrEngineInterface
{
    public const string NAME = 'none';

    public function name(): string
    {
        return self::NAME;
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function supports(string $mimeType, string $filename): bool
    {
        return false;
    }

    public function extract(string $contents, string $mimeType, string $filename): ?string
    {
        return null;
    }
}
