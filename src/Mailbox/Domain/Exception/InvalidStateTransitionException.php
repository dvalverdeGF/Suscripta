<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Exception;

use App\Mailbox\Domain\Enum\MessageProcessingState;
use RuntimeException;

use function sprintf;

/**
 * Se ha intentado una transición que la máquina de estados no permite
 * (ARCHITECTURE.md §13.5).
 *
 * Es un error de programación, no de datos: si el pipeline intenta pasar de
 * `IGNORED` a `MATCHED`, el fallo está en el orquestador. Fallar en voz alta es
 * lo que hace que el log de transiciones sea fiable.
 */
final class InvalidStateTransitionException extends RuntimeException
{
    public static function between(MessageProcessingState $from, MessageProcessingState $to): self
    {
        return new self(sprintf(
            'Transición no permitida: %s → %s.',
            $from->value,
            $to->value,
        ));
    }
}
