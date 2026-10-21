<?php

declare(strict_types=1);

namespace App\Ai\Domain\Enum;

/**
 * Nivel de la IA que interviene en una extracción (ARCHITECTURE.md §13.8).
 *
 * Hay dos porque hay dos costes muy distintos: un modelo económico para el
 * caso normal y uno más caro para los documentos que el económico no resuelve.
 * El nivel se guarda en cada uso para poder medir cuánto cuesta cada uno y si
 * el avanzado aporta lo que se le pedía (D-36).
 */
enum AiTier: string
{
    case CHEAP = 'cheap';
    case ADVANCED = 'advanced';

    public function label(): string
    {
        return match ($this) {
            self::CHEAP => 'IA económica',
            self::ADVANCED => 'IA avanzada',
        };
    }

    /**
     * El nivel avanzado es la **excepción**, no el camino por defecto (D-30).
     */
    public function isEscalation(): bool
    {
        return self::ADVANCED === $this;
    }
}
