<?php

declare(strict_types=1);

namespace App\Ai\Domain\ValueObject;

/**
 * Lo que devuelve un modelo de IA.
 *
 * Se conserva la respuesta cruda además del texto procesado: cuando la
 * salida no es JSON válido, el texto crudo es lo único que permite entender
 * qué devolvió el modelo y decidir si tiene sentido reintentar.
 */
final readonly class AiResponse
{
    /**
     * @param array<string, mixed> $data     salida estructurada ya parseada (vacía si el modelo no devolvió JSON)
     * @param array<string, mixed> $rawUsage métricas crudas del proveedor (tokens, ids…)
     */
    public function __construct(
        public string $content,
        public array $data = [],
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public string $model = '',
        public array $rawUsage = [],
        public int $durationMs = 0,
    ) {
    }

    public function isStructured(): bool
    {
        return [] !== $this->data;
    }

    /**
     * Coste estimado en céntimos.
     *
     * Los precios por token cambian cada pocos meses, así que el valor se
     * calcula en el momento y se persiste como hecho histórico: el coste de
     * una llamada de hace tres meses no puede cambiar bajo los pies (D-36).
     *
     * @param float $costPerMillionPromptTokens     precio por millón de tokens de entrada
     * @param float $costPerMillionCompletionTokens precio por millón de tokens de salida
     */
    public function estimateCostMinor(float $costPerMillionPromptTokens, float $costPerMillionCompletionTokens): int
    {
        $cost = ($this->promptTokens * $costPerMillionPromptTokens
            + $this->completionTokens * $costPerMillionCompletionTokens) / 1_000_000;

        return (int) round($cost * 100);
    }
}
