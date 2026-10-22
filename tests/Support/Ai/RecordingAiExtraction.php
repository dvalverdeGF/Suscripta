<?php

declare(strict_types=1);

namespace App\Tests\Support\Ai;

use App\Ai\Application\AiExtractionInterface;
use App\Ai\Application\Dto\AiExtractionOutcome;
use App\Ai\Domain\Enum\AiSkipReason;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Domain\Dto\ExtractedDocument;

/**
 * Escalera de IA de mentira para probar el pipeline.
 *
 * Devuelve un documento preparado y **cuenta** las veces que se la ha llamado,
 * que es lo que permite comprobar que el pipeline no la invoca cuando no debe:
 * cuando el caso ya está resuelto o cuando la caché ha acertado (D-37).
 *
 * Como la puerta real devuelve un `AiExtractionOutcome` con el motivo
 * (D-57), la doble también: sin documento devuelve `NOT_CONFIGURED`, que es
 * lo que vería una organización sin IA configurada.
 */
final class RecordingAiExtraction implements AiExtractionInterface
{
    private int $calls = 0;

    /**
     * @var list<ExtractedDocument>
     */
    private array $received = [];

    public function __construct(private readonly ?ExtractedDocument $document = null)
    {
    }

    public function enrich(
        ExtractedDocument $deterministic,
        string $haystack,
        ImapMessageHeader $header,
    ): AiExtractionOutcome {
        ++$this->calls;
        $this->received[] = $deterministic;

        if (null === $this->document) {
            return AiExtractionOutcome::skipped(AiSkipReason::NOT_CONFIGURED);
        }

        return AiExtractionOutcome::extracted($this->document);
    }

    public function callCount(): int
    {
        return $this->calls;
    }

    public function wasCalled(): bool
    {
        return $this->calls > 0;
    }

    /**
     * Documentos deterministas que se le han pasado.
     *
     * @return list<ExtractedDocument>
     */
    public function received(): array
    {
        return $this->received;
    }
}
