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
     * Umbrales: ≥ 80 alta, ≥ 55 media, por debajo baja. Son los mismos que usa
     * el `ServiceMatcher` para decidir entre asociar y proponer (§13.10).
     */
    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 80 => self::HIGH,
            $score >= 55 => self::MEDIUM,
            default => self::LOW,
        };
    }
}
