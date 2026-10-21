<?php

declare(strict_types=1);

namespace App\Ai\Domain\Contract;

use App\Ai\Domain\ValueObject\ExtractionContext;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Processing\Domain\Dto\ExtractedDocument;

/**
 * Extrae los datos de un documento usando un nivel de la escalera.
 *
 * ## La escalera, no el interruptor
 *
 * Cada implementación cubre **un** nivel:
 *
 * | Nivel | Implementación | Cuándo entra |
 * |---|---|---|
 * | 1-2 | `DeterministicExtractor` | Siempre, primero |
 * | 3-4 | `ExtractWithProviderParser` | Cuando hay parser conocido del proveedor |
 * | 5 | `AiDocumentExtractor` | Cuando la determinista no ha llegado al importe |
 * | 6 | `AdvancedAiDocumentExtractor` | Cuando la 5 no basta y el caso lo amerita |
 *
 * La escalera es deliberada: cada peldaño cuesta más que el anterior, así que
 * se sube **solo** cuando el anterior ha fallado, y se registra en qué peldaño
 * se resolvió cada documento.
 *
 * ## `supports()` es una decisión, no una comprobación de tipo
 *
 * `supports()` decide si este extractor debe entrar en el caso concreto. No
 * es «¿sabe hacerlo?» sino «¿hace falta aquí?». Por eso el nivel 6 no se
 * activa por ser el más listo, sino por cumplir unas condiciones concretas
 * (umbral de confianza, importe dudoso, revisión pendiente…).
 */
interface DocumentExtractorInterface
{
    /**
     * ¿Este extractor debe intervenir en este caso?
     */
    public function supports(ExtractionContext $context): bool;

    /**
     * Extrae los datos.
     *
     * @throws AiProviderException si el proveedor falla
     */
    public function extract(ExtractionContext $context): ExtractedDocument;

    /**
     * Nivel de la escalera que cubre este extractor.
     */
    public function tier(): ExtractionTier;
}
