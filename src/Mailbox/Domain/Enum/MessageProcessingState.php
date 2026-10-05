<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Enum;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;

/**
 * Máquina de estados del pipeline de análisis (ARCHITECTURE.md §13.5, D-32).
 *
 * Cada mensaje tiene un estado explícito y consultable. Sin él, un pipeline con
 * reintentos, escalado a IA y procesamiento diferido es imposible de depurar:
 * no se sabe si un mensaje se ignoró por puntuación, falló, o está esperando
 * presupuesto.
 *
 * En la Fase 3 solo se recorren `RECEIVED`, `IGNORED`, `CANDIDATE`, `EXTRACTED`,
 * `DISCOVERY` y `FAILED`, pero la máquina completa se define ya: añadir estados
 * después obligaría a reprocesar todo el histórico.
 */
enum MessageProcessingState: string
{
    case RECEIVED = 'received';
    case IGNORED = 'ignored';
    case CANDIDATE = 'candidate';
    case EXTRACTED = 'extracted';
    case CLASSIFIED = 'classified';
    case MATCHED = 'matched';
    case DISCOVERY = 'discovery';
    case REQUIRES_REVIEW = 'requires_review';
    case DEFERRED = 'deferred';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::RECEIVED => 'Recibido',
            self::IGNORED => 'Descartado',
            self::CANDIDATE => 'Candidato',
            self::EXTRACTED => 'Extraído',
            self::CLASSIFIED => 'Clasificado',
            self::MATCHED => 'Asociado a un servicio',
            self::DISCOVERY => 'Descubrimiento propuesto',
            self::REQUIRES_REVIEW => 'Necesita revisión',
            self::DEFERRED => 'Aplazado',
            self::FAILED => 'Fallido',
        };
    }

    /**
     * Estados desde los que no se sale solo. `IGNORED` es terminal pero
     * revisable a mano; `MATCHED` y `DISCOVERY` lo son porque el ciclo continúa
     * en el `Discovery`, no en el mensaje.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::IGNORED, self::MATCHED, self::DISCOVERY, self::FAILED], true);
    }

    /**
     * Estados en los que el pipeline todavía puede avanzar por sí solo.
     *
     * `REQUIRES_REVIEW` y `DEFERRED` quedan fuera a propósito: el primero
     * espera a una persona y el segundo a presupuesto de IA, así que
     * reencolarlos en cada sincronización solo gastaría ciclos para volver al
     * mismo sitio.
     */
    public function isPending(): bool
    {
        return in_array($this, [self::RECEIVED, self::CANDIDATE, self::EXTRACTED, self::CLASSIFIED], true);
    }

    /**
     * @return list<string>
     */
    public static function pendingValues(): array
    {
        return array_values(array_map(
            static fn (self $state): string => $state->value,
            array_filter(self::cases(), static fn (self $state): bool => $state->isPending()),
        ));
    }

    /**
     * Transiciones permitidas. Cualquier otra se rechaza en
     * `MessageStateMachine`, que es lo que hace que el log de transiciones sea
     * fiable.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::RECEIVED => [self::IGNORED, self::CANDIDATE, self::FAILED],
            self::IGNORED => [self::CANDIDATE],
            self::CANDIDATE => [self::EXTRACTED, self::DEFERRED, self::FAILED],
            self::EXTRACTED => [self::CLASSIFIED, self::REQUIRES_REVIEW, self::FAILED],
            self::CLASSIFIED => [self::MATCHED, self::DISCOVERY, self::REQUIRES_REVIEW],
            self::MATCHED => [],
            self::DISCOVERY => [],
            self::REQUIRES_REVIEW => [self::EXTRACTED, self::IGNORED],
            self::DEFERRED => [self::CANDIDATE, self::EXTRACTED, self::FAILED],
            self::FAILED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
