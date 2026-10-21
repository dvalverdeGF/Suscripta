<?php

declare(strict_types=1);

namespace App\Ai\Application;

use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Domain\Dto\ExtractedDocument;

/**
 * Puerta de entrada a la escalera de IA.
 *
 * El pipeline depende de esta interfaz y no de la implementación concreta
 * porque la política de IA (qué se manda, qué se redacta, cuándo se llama)
 * pertenece al módulo de IA, mientras que la política de procesamiento
 * (qué se extrae, cuándo se propone) pertenece al de procesamiento. Que la
 * frontera sea explícita es lo que permite probar el pipeline sin gastar un
 * céntimo en tokens.
 */
interface AiExtractionInterface
{
    /**
     * Intenta mejorar el documento con IA.
     *
     * Devuelve `null` cuando no hay nada que mejorar, cuando la IA está
     * desactivada, cuando el presupuesto se ha agotado o cuando el modelo ha
     * fallado. El pipeline trata todos esos casos igual: sigue con el
     * documento determinista.
     */
    public function enrich(
        ExtractedDocument $deterministic,
        string $haystack,
        ImapMessageHeader $header,
    ): ?ExtractedDocument;
}
