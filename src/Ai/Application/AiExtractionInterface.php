<?php

declare(strict_types=1);

namespace App\Ai\Application;

use App\Ai\Application\Dto\AiExtractionOutcome;
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
     * Devuelve el documento mejorado o un `AiExtractionOutcome` **con el
     * motivo** de por qué no se ha mejorado (Fase 14, D-57). El motivo es lo
     * que permite distinguir un correo que está esperando presupuesto
     * (`DEFERRED`) de uno que se ha revisado y no va a mejorar
     * (`REQUIRES_REVIEW`).
     */
    public function enrich(
        ExtractedDocument $deterministic,
        string $haystack,
        ImapMessageHeader $header,
    ): AiExtractionOutcome;
}
