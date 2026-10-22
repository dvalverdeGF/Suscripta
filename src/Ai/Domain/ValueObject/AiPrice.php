<?php

declare(strict_types=1);

namespace App\Ai\Domain\ValueObject;

/**
 * Precio de un modelo, en euros por millón de tokens.
 *
 * ## Por qué el precio se guarda como hecho
 *
 * El precio de un token cambia cada pocos meses. Si el coste de una llamada
 * se calculara **al mostrar el informe**, el mismo histórico daría cifras
 * distintas cada vez que el proveedor subiera sus tarifas. Por eso el coste
 * se calcula en el momento de la llamada y se persiste (D-36): el informe
 * cuenta lo que se pagó, no lo que se pagaría hoy.
 *
 * ## De dónde salen los precios
 *
 * Los precios por defecto son orientativos y se guardan en la integración,
 * que es donde el operador puede ajustarlos. Un cliente que usa un modelo
 * autoalojado (Ollama) no paga nada: su coste es cero, y el coste real —la
 * CPU que consume— no se puede medir desde aquí.
 */
final readonly class AiPrice
{
    /**
     * @param float $promptPerMillion     euros por millón de tokens de entrada
     * @param float $completionPerMillion euros por millón de tokens de salida
     */
    public function __construct(
        public float $promptPerMillion = 0.0,
        public float $completionPerMillion = 0.0,
    ) {
    }

    /**
     * Precio nulo: lo que no se cobra a la API.
     */
    public static function free(): self
    {
        return new self(0.0, 0.0);
    }

    /**
     * Coste en **microcéntimos** de una llamada concreta.
     *
     * Se guarda en microcéntimos (D-68) porque una llamada a un modelo
     * económico cuesta céntimas: redondeando a céntimos, casi todas las
     * llamadas se registrarían como coste cero y el panel de gasto
     * subestimaría lo que se está pagando.
     *
     * @param int $promptTokens     tokens de entrada
     * @param int $completionTokens tokens de salida
     */
    public function costMicro(int $promptTokens, int $completionTokens): int
    {
        $euros = ($promptTokens * $this->promptPerMillion
            + $completionTokens * $this->completionPerMillion) / 1_000_000;

        return (int) round($euros * AiCost::MICRO_PER_EURO);
    }
}
