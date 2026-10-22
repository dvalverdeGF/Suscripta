<?php

declare(strict_types=1);

namespace App\Ai\Application;

use App\Ai\Application\Dto\AiExtractionOutcome;
use App\Ai\Domain\Enum\AiSkipReason;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Domain\Dto\ExtractedDocument;

/**
 * Escalera de IA desactivada: no hace nada.
 *
 * Existe para que el pipeline no necesite saber si hay IA configurada. Un
 * pipeline que comprueba «¿tengo IA?» tiene dos caminos, y el que no la tiene
 * acaba sin probarse nunca.
 *
 * También es la implementación por defecto cuando la organización no ha
 * activado la IA: en ese caso, el sistema funciona exactamente igual que en
 * la Fase 3, con extracción determinista y revisión manual para lo que no se
 * puede resolver (D-08).
 *
 * Desde la Fase 14 devuelve un motivo (`NOT_CONFIGURED`) en vez de un `null`
 * pelado, para que el pipeline pueda distinguir «no hay IA» de «no hay
 * presupuesto» (D-57).
 */
final readonly class NullAiExtraction implements AiExtractionInterface
{
    public function enrich(
        ExtractedDocument $deterministic,
        string $haystack,
        ImapMessageHeader $header,
    ): AiExtractionOutcome {
        return AiExtractionOutcome::skipped(AiSkipReason::NOT_CONFIGURED);
    }
}
