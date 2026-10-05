<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailbox\Domain\Enum;

use App\Mailbox\Domain\Enum\MessageProcessingState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * La máquina de estados es lo que hace que el log de transiciones sea fiable:
 * si cualquier estado pudiera saltar a cualquier otro, no se podría saber por
 * qué un mensaje acabó donde acabó.
 */
#[CoversClass(MessageProcessingState::class)]
final class MessageProcessingStateTest extends TestCase
{
    public function testEveryStateHasALabel(): void
    {
        foreach (MessageProcessingState::cases() as $state) {
            self::assertNotSame('', $state->label(), $state->value);
        }
    }

    public function testTerminalStatesHaveNoTransitions(): void
    {
        foreach ([MessageProcessingState::MATCHED, MessageProcessingState::DISCOVERY, MessageProcessingState::FAILED] as $state) {
            self::assertTrue($state->isTerminal(), $state->value);
            self::assertSame([], $state->allowedTransitions(), $state->value);
        }
    }

    public function testIgnoredIsTerminalButReviewable(): void
    {
        self::assertTrue(MessageProcessingState::IGNORED->isTerminal());
        self::assertTrue(MessageProcessingState::IGNORED->canTransitionTo(MessageProcessingState::CANDIDATE));
    }

    public function testHappyPathIsAllowed(): void
    {
        self::assertTrue(MessageProcessingState::RECEIVED->canTransitionTo(MessageProcessingState::CANDIDATE));
        self::assertTrue(MessageProcessingState::CANDIDATE->canTransitionTo(MessageProcessingState::EXTRACTED));
        self::assertTrue(MessageProcessingState::EXTRACTED->canTransitionTo(MessageProcessingState::CLASSIFIED));
        self::assertTrue(MessageProcessingState::CLASSIFIED->canTransitionTo(MessageProcessingState::MATCHED));
        self::assertTrue(MessageProcessingState::CLASSIFIED->canTransitionTo(MessageProcessingState::DISCOVERY));
    }

    public function testShortcutsAreRejected(): void
    {
        self::assertFalse(MessageProcessingState::RECEIVED->canTransitionTo(MessageProcessingState::MATCHED));
        self::assertFalse(MessageProcessingState::CANDIDATE->canTransitionTo(MessageProcessingState::DISCOVERY));
        self::assertFalse(MessageProcessingState::EXTRACTED->canTransitionTo(MessageProcessingState::MATCHED));
    }

    public function testNoStateTransitionsToItself(): void
    {
        foreach (MessageProcessingState::cases() as $state) {
            self::assertFalse($state->canTransitionTo($state), $state->value);
        }
    }

    /**
     * `REQUIRES_REVIEW` espera a una persona y `DEFERRED` a presupuesto de IA:
     * reencolarlos en cada sincronización solo gastaría ciclos para volver al
     * mismo sitio.
     */
    public function testPendingExcludesStatesWaitingOnExternalInput(): void
    {
        self::assertTrue(MessageProcessingState::RECEIVED->isPending());
        self::assertTrue(MessageProcessingState::CANDIDATE->isPending());
        self::assertTrue(MessageProcessingState::EXTRACTED->isPending());
        self::assertTrue(MessageProcessingState::CLASSIFIED->isPending());

        self::assertFalse(MessageProcessingState::REQUIRES_REVIEW->isPending());
        self::assertFalse(MessageProcessingState::DEFERRED->isPending());
        self::assertFalse(MessageProcessingState::IGNORED->isPending());
        self::assertFalse(MessageProcessingState::MATCHED->isPending());
        self::assertFalse(MessageProcessingState::DISCOVERY->isPending());
        self::assertFalse(MessageProcessingState::FAILED->isPending());
    }

    public function testPendingValuesAreTheRawColumnValues(): void
    {
        self::assertSame(
            ['received', 'candidate', 'extracted', 'classified'],
            MessageProcessingState::pendingValues(),
        );
    }
}
