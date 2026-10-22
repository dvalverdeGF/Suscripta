<?php

declare(strict_types=1);

namespace App\Observability\Application\Dto;

use App\Ai\Application\Dto\AiCostReport;
use App\Shared\Domain\ValueObject\DateRange;

/**
 * Informe de observabilidad de producto (Fase 17).
 *
 * Reúne en un solo sitio las cuatro preguntas que un operador se hace sobre
 * el pipeline: si entra el correo, si se procesa, cuánto cuesta procesarlo y
 * si el sistema le avisa de lo que tiene que hacer.
 *
 * Es un DTO de solo lectura, como todos los de la capa de aplicación: la
 * vista pinta, no calcula.
 */
final readonly class ObservabilityReport
{
    public function __construct(
        public DateRange $period,
        public SyncActivity $sync,
        public ExtractionActivity $extraction,
        public AiCostReport $ai,
        public AlertActivity $alerts,
    ) {
    }

    /**
     * ¿Hay algo que vaya mal?
     *
     * Se usa para poner un distintivo en la parte superior del informe: un
     * panel de observabilidad que no dice si hay un problema es un panel de
     * decoración.
     */
    public function hasProblems(): bool
    {
        return $this->sync->failed > 0
            || $this->ai->failures > 0
            || $this->ai->budget->exhausted
            || $this->alerts->open > 0;
    }
}
