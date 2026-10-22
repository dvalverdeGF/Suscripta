<?php

declare(strict_types=1);

namespace App\Ai\Application\Extraction;

use App\Ai\Domain\Enum\AiSkipReason;
use App\Processing\Domain\Dto\ExtractedDocument;

/**
 * Lo que ha devuelto la escalera de IA, y por qué (Fase 14).
 *
 * Antes la cadena devolvía `?ExtractedDocument` y el pipeline recibía un
 * `null` sin explicación. Con el motivo explícito, el panel de observabilidad
 * puede distinguir tres cosas que antes eran la misma:
 *
 * - **No hacía falta** (`NOT_NEEDED`): la escalera no tenía nada que hacer.
 * - **Falló** (`FAILED`): se llamó al proveedor y no devolvió nada válido.
 * - **No había IA** (`NOT_CONFIGURED`): no hay proveedor elegido.
 *
 * Solo uno de esos motivos aparta el correo de la revisión manual: el
 * presupuesto agotado, que se comprueba antes de entrar en la cadena.
 */
final readonly class ChainResult
{
    private function __construct(
        public ?ExtractedDocument $document,
        public AiSkipReason $reason,
    ) {
    }

    /**
     * La escalera ha producido un documento.
     */
    public static function extracted(ExtractedDocument $document): self
    {
        return new self($document, AiSkipReason::NONE);
    }

    /**
     * La escalera no ha producido nada, y esto es el motivo.
     */
    public static function skipped(AiSkipReason $reason): self
    {
        return new self(null, $reason);
    }

    public function hasDocument(): bool
    {
        return null !== $this->document;
    }
}
