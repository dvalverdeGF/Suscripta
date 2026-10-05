<?php

declare(strict_types=1);

namespace App\Processing\Domain\Ocr;

/**
 * Reconocimiento óptico de caracteres (ARCHITECTURE.md §13.6, D-24).
 *
 * Se usa **solo** cuando el documento no trae capa de texto: un PDF escaneado o
 * una foto de una factura. Nunca sustituye a la extracción determinista, y
 * nunca sale de nuestra infraestructura.
 *
 * `isAvailable()` existe porque el motor es opcional: en un entorno sin
 * Tesseract instalado el pipeline debe seguir funcionando (degradando a IA o a
 * revisión), no romperse.
 */
interface OcrEngineInterface
{
    public function name(): string;

    /**
     * ¿Hay motor instalado y utilizable en este entorno?
     */
    public function isAvailable(): bool;

    /**
     * ¿Este formato se puede rasterizar para leerlo?
     */
    public function supports(string $mimeType, string $filename): bool;

    /**
     * Texto reconocido, o `null` si no se pudo leer nada.
     */
    public function extract(string $contents, string $mimeType, string $filename): ?string;
}
