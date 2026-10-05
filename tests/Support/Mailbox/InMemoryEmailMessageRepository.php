<?php

declare(strict_types=1);

namespace App\Tests\Support\Mailbox;

use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;

use function array_slice;
use function array_values;
use function count;

use Symfony\Component\Uid\Uuid;

/**
 * Repositorio de mensajes en memoria con almacenamiento real.
 *
 * Guarda las **mismas instancias** que recibe, de modo que una prueba puede
 * inspeccionar el estado de un mensaje después de ejecutar un caso de uso.
 */
final class InMemoryEmailMessageRepository implements EmailMessageRepositoryInterface
{
    /** @var array<string, EmailMessage> */
    private array $messages = [];

    public int $flushCount = 0;

    /** @return list<EmailMessage> */
    public function all(): array
    {
        return array_values($this->messages);
    }

    public function find(Uuid $id): ?EmailMessage
    {
        return $this->messages[$id->toRfc4122()] ?? null;
    }

    public function findByAccountFolderUid(Uuid $emailAccountId, string $folder, int $uid): ?EmailMessage
    {
        foreach ($this->messages as $message) {
            if ($message->getEmailAccountId()->equals($emailAccountId)
                && $message->getFolder() === $folder
                && $message->getUid() === $uid
            ) {
                return $message;
            }
        }

        return null;
    }

    public function findByAccountAndMessageId(Uuid $emailAccountId, string $messageId): ?EmailMessage
    {
        foreach ($this->messages as $message) {
            if ($message->getEmailAccountId()->equals($emailAccountId)
                && $message->getMessageId() === $messageId
            ) {
                return $message;
            }
        }

        return null;
    }

    public function findByContentHash(string $contentHash): ?EmailMessage
    {
        foreach ($this->messages as $message) {
            if ($message->getContentHash() === $contentHash) {
                return $message;
            }
        }

        return null;
    }

    public function findForOrganization(?MessageProcessingState $state = null, int $limit = 50): array
    {
        $found = [];

        foreach ($this->messages as $message) {
            if (null !== $state && $message->getProcessingState() !== $state) {
                continue;
            }

            $found[] = $message;
        }

        return array_slice($found, 0, $limit);
    }

    public function findPendingForAccount(Uuid $emailAccountId, int $limit = 200): array
    {
        $found = [];

        foreach ($this->messages as $message) {
            if (!$message->getEmailAccountId()->equals($emailAccountId)) {
                continue;
            }

            if (!$message->getProcessingState()->isPending()) {
                continue;
            }

            $found[] = $message;
        }

        return array_slice($found, 0, $limit);
    }

    public function countByState(): array
    {
        $counts = [];

        foreach ($this->messages as $message) {
            $key = $message->getProcessingState()->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    public function countForOrganization(): int
    {
        return count($this->messages);
    }

    public function countForAccount(Uuid $emailAccountId): int
    {
        $count = 0;

        foreach ($this->messages as $message) {
            if ($message->getEmailAccountId()->equals($emailAccountId)) {
                ++$count;
            }
        }

        return $count;
    }

    public function countByAccount(): array
    {
        $counts = [];

        foreach ($this->messages as $message) {
            $key = $message->getEmailAccountId()->toRfc4122();
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    public function save(EmailMessage $message, bool $flush = true): void
    {
        $this->messages[$message->getId()->toRfc4122()] = $message;

        if ($flush) {
            $this->flush();
        }
    }

    public function saveAll(iterable $messages): void
    {
        foreach ($messages as $message) {
            $this->messages[$message->getId()->toRfc4122()] = $message;
        }
    }

    public function flush(): void
    {
        ++$this->flushCount;
    }

    public function removeForAccount(Uuid $emailAccountId): int
    {
        $removed = 0;

        foreach ($this->messages as $key => $message) {
            if ($message->getEmailAccountId()->equals($emailAccountId)) {
                unset($this->messages[$key]);
                ++$removed;
            }
        }

        return $removed;
    }
}
