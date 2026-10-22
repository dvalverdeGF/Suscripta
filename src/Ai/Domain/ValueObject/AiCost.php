<?php

declare(strict_types=1);

namespace App\Ai\Domain\ValueObject;

/**
 * Unidad de coste de la IA: **microcéntimos**.
 *
 * ## Por qué microcéntimos y no céntimos
 *
 * Una llamada a un modelo económico cuesta céntimas. Con Groq, un millón de
 * tokens de entrada cuesta 0,59 €: una llamada normal cuesta 0,0034 €. Si el
 * libro mayor guardase céntimos, el redondeo a cero borraría la mayor parte
 * de las llamadas y el panel enseñaría «0,00 €» de un gasto real de 0,14 €
 * (D-68).
 *
 * Guardando en microcéntimos (1 € = 1 000 000) el coste de cada llamada
 * conserva su valor exacto, la suma es exacta y el redondeo a céntimos
 * ocurre **una sola vez**, al pintar.
 *
 * ## Dónde se usa cada unidad
 *
 * - **Microcéntimos**: almacenamiento (`ai_usage.cost_micro`) y agregaciones.
 * - **Céntimos**: tops de presupuesto, que son cantidades que configura una
 *   persona, y textos de aviso.
 *
 * La conversión vive aquí y en ningún otro sitio.
 */
final class AiCost
{
    /**
     * Microcéntimos por céntimo.
     */
    public const int MICRO_PER_MINOR = 10_000;

    /**
     * Microcéntimos por euro.
     */
    public const int MICRO_PER_EURO = 1_000_000;

    private function __construct()
    {
    }

    /**
     * Microcéntimos a céntimos, redondeando al céntimo más cercano.
     */
    public static function toMinor(int $micro): int
    {
        return (int) round($micro / self::MICRO_PER_MINOR);
    }

    /**
     * Céntimos a microcéntimos.
     */
    public static function toMicro(int $minor): int
    {
        return $minor * self::MICRO_PER_MINOR;
    }
}
