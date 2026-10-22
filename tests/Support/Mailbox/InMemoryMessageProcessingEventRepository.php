<?php

declare(strict_types=1);

namespace App\Tests\Support\Mailbox;

use App\Mailbox\Domain\Entity\MessageProcessingEvent;
use App\Mailbox\Domain\Repository\MessageProcessingEventRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Historial de transiciones en memoria.
 *
 * La máquina de estados escribe aquí cada vez que cambia el estado de un
 * correo. Guardar de verdad el historial permite comprobar, en el test, que
 * un correo pasó por los estados esperados y en el orden esperado.
 */
final class InMemoryMessageProcessingEventRepository implements MessageProcessingEventRepositoryInterface
{
    /** @var list<MessageProcessingEvent> */
    private array $events = [];

    public function findForMessage(Uuid $emailMessageId): array
    {
        $found = [];

        foreach ($this->events as $event) {
            if ($event->getEmailMessageId()->equals($emailMessageId)) {
                $found[] = $event;
            }
        }

        return $found;
    }

    public function save(MessageProcessingEvent $event, bool $flush = true): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<MessageProcessingEvent>
     */
    public function all(): array
    {
        return $this->events;
    }
}
