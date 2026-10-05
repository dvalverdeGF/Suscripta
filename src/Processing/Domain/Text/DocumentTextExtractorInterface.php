<?php

declare(strict_types=1);

namespace App\Processing\Domain\Text;

/**
 * Extrae el texto de un documento **sin salir de nuestra infraestructura**
 * (ARCHITECTURE.md §13.6, D-24).
 *
 * Es un puerto y no una clase concreta porque el formato manda: un PDF con capa
 * de texto se resuelve con una librería, un PDF escaneado necesita OCR y un
 * `.txt` no necesita nada. El pipeline no debe saber cuál de los tres ha
 * ocurrido, solo si obtuvo texto.
 *
 * El contrato es deliberadamente tolerante: devolver `null` significa "no es mi
 * formato" y devolver una cadena vacía significa "es mi formato pero no tiene
 * texto". La diferencia importa, porque solo el segundo caso justifica pagar un
 * OCR.
 */
interface DocumentTextExtractorInterface
{
    /**
     * Identificador estable que se guarda en el log de procesamiento.
     */
    public function name(): string;

    /**
     * Orden de intento: gana el número más alto.
     *
     * Existe porque el orden no puede depender del orden en que el contenedor
     * descubra los archivos. Un extractor específico (PDF, DOCX) debe ir antes
     * que uno genérico que también acepte el formato.
     */
    public function priority(): int;

    /**
     * ¿Este extractor sabe leer este formato?
     */
    public function supports(string $mimeType, string $filename): bool;

    /**
     * Texto plano del documento, o `null` si no es su formato.
     */
    public function extract(string $contents, string $mimeType, string $filename): ?string;
}
