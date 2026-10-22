<?php

declare(strict_types=1);

namespace App\Tests\Support\Mailbox;

use App\Mailbox\Domain\Entity\MessageProcessingEvent;
use App\Mailbox\Domain\Repository\MessageProcessingEventRepositoryInterface;
use DateTimeImmutable;
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

    /**
     * Mensajes y su organización, para poder borrar por organización.
     *
     * @var array<string, string> identificador del mensaje => organización
     */
    private array $messageOrganizations = [];

    /**
     * Registra a qué organización pertenece un mensaje.
     *
     * Los eventos solo guardan el identificador del mensaje, igual que en la
     * base de datos; el borrado por organización necesita saber de quién es
     * cada mensaje, y aquí se lo dice la prueba.
     */
    public function linkMessageToOrganization(Uuid $emailMessageId, Uuid $organizationId): void
    {
        $this->messageOrganizations[$emailMessageId->toRfc4122()] = $organizationId->toRfc4122();
    }

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

    public function removeForAccount(Uuid $emailAccountId): int
    {
        return $this->removeWhere(
            static fn (MessageProcessingEvent $event): bool => $event->getEmailMessageId()->equals($emailAccountId),
        );
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        $organization = $organizationId->toRfc4122();

        return $this->removeWhere(
            function (MessageProcessingEvent $event) use ($organization): bool {
                $owner = $this->messageOrganizations[$event->getEmailMessageId()->toRfc4122()] ?? null;

                return null !== $owner && $owner === $organization;
            },
        );
    }

    public function purgeOlderThan(DateTimeImmutable $threshold, int $limit = 500): int
    {
        return $this->removeWhere(
            static fn (MessageProcessingEvent $event): bool => $event->getOccurredAt() < $threshold,
            $limit,
        );
    }

    /**
     * @param callable(MessageProcessingEvent): bool $predicate
     */
    private function removeWhere(callable $predicate, ?int $limit = null): int
    {
        $removed = 0;
        $kept = [];

        foreach ($this->events as $event) {
            if ($predicate($event) && (null === $limit || $removed < $limit)) {
                ++$removed;

                continue;
            }

            $kept[] = $event;
        }

        $this->events = $kept;

        return $removed;
    }
}
