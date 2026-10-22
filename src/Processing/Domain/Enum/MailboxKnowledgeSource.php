<?php

declare(strict_types=1);

namespace App\Processing\Domain\Enum;

/**
 * De dónde sale cada conocimiento aprendido (ARCHITECTURE.md §13.12).
 *
 * El origen decide cuánto se confía en lo aprendido y cómo se poda:
 *
 * - `OBSERVED`: deducido por el sistema al procesar un correo. Es la fuente
 *   más plentiful y la menos fiable: puede haber leído mal un documento.
 * - `CONFIRMATION`: el usuario confirmó la propuesta. Es una afirmación del
 *   usuario, no una deducción.
 * - `USER`: el usuario lo escribió o lo editó a mano en la pantalla de
 *   conocimiento.
 * - `PROMOTED`: el conocimiento del buzón se promovió a catálogo global. No
 *   cambia la confianza, pero deja de depender de este buzón.
 */
enum MailboxKnowledgeSource: string
{
    case OBSERVED = 'observed';
    case CONFIRMATION = 'confirmation';
    case USER = 'user';
    case PROMOTED = 'promoted';

    public function label(): string
    {
        return match ($this) {
            self::OBSERVED => 'Observado',
            self::CONFIRMATION => 'Confirmado por ti',
            self::USER => 'Editado por ti',
            self::PROMOTED => 'Promovido',
        };
    }

    /**
     * Confianza inicial según el origen. Un dato que el usuario ha confirmado
     * vale más que uno deducido por el sistema.
     */
    public function defaultConfidence(): int
    {
        return match ($this) {
            self::OBSERVED => 60,
            self::CONFIRMATION => 90,
            self::USER => 100,
            self::PROMOTED => 80,
        };
    }

    /**
     * Solo el usuario puede afirmar algo; lo Promote() nunca lo hace.
     */
    public function isUserAuthored(): bool
    {
        return match ($this) {
            self::CONFIRMATION, self::USER => true,
            default => false,
        };
    }
}
