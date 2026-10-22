<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Enum;

/**
 * Nivel de confianza de una propuesta (ARCHITECTURE.md §4.6).
 *
 * Es una **vista derivada** de `confidenceScore`, no un dato independiente: se
 * calcula siempre a partir del número para que no puedan contradecirse. Existe
 * porque la interfaz necesita tres niveles, no cien.
 */
enum DiscoveryConfidence: string
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
     * Umbrales de la **confianza de extracción**: ≥ 80 alta, ≥ 55 media, por
     * debajo baja.
     *
     * No son los umbrales del emparejamiento (70/40, §13.10): estos miden
     * cuánto confía el extractor en los datos que ha leído, y aquellos miden
     * cuánto se parece un documento a un servicio que ya existe. Son dos
     * preguntas distintas y por eso tienen dos escalas distintas.
     */
    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 80 => self::HIGH,
            $score >= 55 => self::MEDIUM,
            default => self::LOW,
        };
    }

    /**
     * Puntuación máxima que sigue dentro de esta banda.
     *
     * Sirve para **recortar** una puntuación a una banda sin perder del todo
     * la información: una inferencia nunca puede estar en la banda alta, pero
     * su puntuación sigue diciendo cuán segura está la deducción (D-59).
     */
    public function maxScore(): int
    {
        return match ($this) {
            self::HIGH => 100,
            self::MEDIUM => 79,
            self::LOW => 54,
        };
    }
}
