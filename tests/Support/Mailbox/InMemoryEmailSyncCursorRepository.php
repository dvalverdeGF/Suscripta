<?php

declare(strict_types=1);

namespace App\Tests\Support\Mailbox;

use App\Mailbox\Domain\Entity\EmailSyncCursor;
use App\Mailbox\Domain\Repository\EmailSyncCursorRepositoryInterface;

use function array_values;

use Symfony\Component\Uid\Uuid;

/**
 * Cursor en memoria para las pruebas.
 *
 * Guarda las instancias reales, no copias: así una prueba puede comprobar el
 * estado del cursor después de la sincronización sin volver a consultarlo.
 */
final class InMemoryEmailSyncCursorRepository implements EmailSyncCursorRepositoryInterface
{
    /** @var array<string, EmailSyncCursor> */
    private array $cursors = [];

    public int $flushCount = 0;

    public function find(Uuid $id): ?EmailSyncCursor
    {
        return $this->cursors[$id->toRfc4122()] ?? null;
    }

    public function findForAccountFolder(Uuid $emailAccountId, string $folder): ?EmailSyncCursor
    {
        foreach ($this->cursors as $cursor) {
            if ($cursor->getEmailAccountId()->equals($emailAccountId) && $cursor->getFolder() === $folder) {
                return $cursor;
            }
        }

        return null;
    }

    /**
     * @return list<EmailSyncCursor>
     */
    public function findForAccount(Uuid $emailAccountId): array
    {
        $found = [];

        foreach ($this->cursors as $cursor) {
            if ($cursor->getEmailAccountId()->equals($emailAccountId)) {
                $found[] = $cursor;
            }
        }

        return $found;
    }

    public function save(EmailSyncCursor $cursor, bool $flush = true): void
    {
        $this->cursors[$cursor->getId()->toRfc4122()] = $cursor;

        if ($flush) {
            ++$this->flushCount;
        }
    }

    public function removeForAccount(Uuid $emailAccountId): int
    {
        $removed = 0;

        foreach ($this->cursors as $key => $cursor) {
            if ($cursor->getEmailAccountId()->equals($emailAccountId)) {
                unset($this->cursors[$key]);
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * @return list<EmailSyncCursor>
     */
    public function all(): array
    {
        return array_values($this->cursors);
    }
}
