<?php

declare(strict_types=1);

namespace App\Processing\Domain\Matching;

/**
 * Zona de confianza de un emparejamiento (ARCHITECTURE.md §13.10).
 *
 * Es una **vista derivada de la puntuación**, no un dato independiente: se
 * calcula siempre a partir del número con los umbrales vigentes, de modo que
 * puntuación y zona no puedan contradecirse. Existe porque la interfaz y el
 * pipeline necesitan tres zonas, no cien puntuaciones:
 *
 * - `HIGH`: el documento pertenece con suficiente certeza a un servicio que ya
 *   existe y se puede asociar sin preguntar.
 * - `MEDIUM`: hay un candidato razonable, pero la decisión es del usuario.
 * - `LOW`: no hay candidato creíble y lo que procede es proponer un servicio
 *   nuevo.
 *
 * Los umbrales no están en el enum: son **configuración de producto** y los
 * aporta quien puntúa (ver `ServiceMatchWeights`).
 */
enum MatchConfidence: string
{
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';

    public function label(): string
    {
        return match ($this) {
            self::HIGH => 'Alta',
            self::MEDIUM => 'Media',
            self::LOW => 'Baja',
        };
    }

    /**
     * Traduce una puntuación a zona usando los umbrales configurados.
     *
     * El orden de las comprobaciones importa: `high` se mira antes que
     * `medium` porque los umbrales no tienen por qué estar ordenados, y lo que
     * se quiere es «el primero que se supera gana».
     */
    public static function fromScore(int $score, int $highThreshold, int $mediumThreshold): self
    {
        return match (true) {
            $score >= $highThreshold => self::HIGH,
            $score >= $mediumThreshold => self::MEDIUM,
            default => self::LOW,
        };
    }
}
