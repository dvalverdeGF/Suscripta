<?php

declare(strict_types=1);

namespace App\Processing\Application;

use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Entity\MessageProcessingEvent;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Exception\InvalidStateTransitionException;
use App\Mailbox\Domain\Repository\MessageProcessingEventRepositoryInterface;
use App\Shared\Application\Clock;
use Symfony\Component\Uid\Uuid;

/**
 * Máquina de estados del pipeline (ARCHITECTURE.md §13.5, D-32).
 *
 * Es el **único** sitio del proyecto autorizado a cambiar
 * `EmailMessage.processingState`. Centralizarlo garantiza dos cosas: que no
 * existen transiciones imposibles y que **toda** transición deja rastro en
 * `MessageProcessingEvent`.
 *
 * Sin esto, un pipeline con reintentos, escalado a IA y procesamiento diferido
 * es imposible de depurar: no se sabe si un mensaje se ignoró por puntuación,
 * falló, o está esperando presupuesto.
 */
final readonly class MessageStateMachine
{
    public function __construct(
        private MessageProcessingEventRepositoryInterface $events,
        private Clock $clock,
    ) {
    }

    /**
     * Aplica una transición y la registra.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidStateTransitionException si la transición no está permitida
     */
    public function transition(
        EmailMessage $message,
        MessageProcessingState $to,
        string $reason,
        ?string $extractor = null,
        ?ExtractionTier $tier = null,
        ?Uuid $aiUsageId = null,
        ?int $durationMs = null,
        array $data = [],
    ): void {
        $from = $message->getProcessingState();

        if ($from === $to) {
            return;
        }

        if (!$from->canTransitionTo($to)) {
            throw InvalidStateTransitionException::between($from, $to);
        }

        $message->setProcessingState($to);

        $this->events->save(new MessageProcessingEvent(
            emailMessageId: $message->getId(),
            fromState: $from,
            toState: $to,
            reason: $reason,
            occurredAt: $this->clock->now(),
            extractor: $extractor,
            tier: $tier,
            aiUsageId: $aiUsageId,
            durationMs: $durationMs,
            data: $data,
        ));
    }

    /**
     * ¿Se puede aplicar esta transición sin lanzar? Se usa en el orquestador
     * para decidir si merece la pena intentarlo, y en los tests.
     */
    public function canTransition(EmailMessage $message, MessageProcessingState $to): bool
    {
        return $message->getProcessingState()->canTransitionTo($to);
    }
}
