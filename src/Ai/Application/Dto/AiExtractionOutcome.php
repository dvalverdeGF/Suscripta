<?php

declare(strict_types=1);

namespace App\Ai\Application\Dto;

use App\Ai\Domain\Enum\AiSkipReason;
use App\Processing\Domain\Dto\ExtractedDocument;

/**
 * Lo que ha devuelto la escalera de IA, y por qué (Fase 14).
 *
 * Antes de la Fase 14 la puerta de IA devolvía `?ExtractedDocument` y nada
 * más. Eso obligaba al pipeline a confundir cuatro situaciones muy
 * distintas (IA apagada, presupuesto agotado, límite de peticiones y fallo
 * del proveedor) con un único `null`, y a mandar todas a revisión manual.
 *
 * Con el motivo explícito, el pipeline puede hacer lo correcto:
 *
 * - **Presupuesto agotado** → el correo queda `DEFERRED`, esperando.
 * - **IA apagada o sin texto** → el correo va a revisión, y no va a volver.
 * - **Límite de peticiones** → el correo va a revisión, y se reintentará en la
 *   siguiente sincronización.
 * - **Fallo del proveedor** → el correo va a revisión; reintentar costaría
 *   dinero sin garantías.
 */
final readonly class AiExtractionOutcome
{
    private function __construct(
        public ?ExtractedDocument $document,
        public AiSkipReason $reason,
    ) {
    }

    /**
     * La IA ha producido un documento.
     */
    public static function extracted(ExtractedDocument $document): self
    {
        return new self($document, AiSkipReason::NONE);
    }

    /**
     * La IA no ha producido nada, y esto es el motivo.
     */
    public static function skipped(AiSkipReason $reason): self
    {
        return new self(null, $reason);
    }

    public function hasDocument(): bool
    {
        return null !== $this->document;
    }

    public function isSkipped(): bool
    {
        return null === $this->document;
    }

    /**
     * ¿El motivo hace que el correo se aplaze en vez de ir a revisión?
     */
    public function defersMessage(): bool
    {
        return $this->reason->defersMessage();
    }
}
