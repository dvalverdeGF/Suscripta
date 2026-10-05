<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Enum;

/**
 * Con qué mecanismo se obtuvieron los datos (ARCHITECTURE.md §13.1).
 *
 * Se guarda en cada mensaje y en cada descubrimiento para poder medir cuánto
 * trabajo resuelve cada nivel y cuánto cuesta la IA (D-36).
 */
enum ExtractionTier: string
{
    case DETERMINISTIC = 'deterministic';
    case KNOWN_PARSER = 'known_parser';
    case AI_CHEAP = 'ai_cheap';
    case AI_ADVANCED = 'ai_advanced';

    public function label(): string
    {
        return match ($this) {
            self::DETERMINISTIC => 'Extracción determinista',
            self::KNOWN_PARSER => 'Parser de proveedor conocido',
            self::AI_CHEAP => 'IA económica',
            self::AI_ADVANCED => 'IA avanzada',
        };
    }

    public function usedAi(): bool
    {
        return self::AI_CHEAP === $this || self::AI_ADVANCED === $this;
    }
}
