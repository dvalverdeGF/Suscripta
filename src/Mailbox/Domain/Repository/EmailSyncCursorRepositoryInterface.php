<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Repository;

use App\Mailbox\Domain\Entity\EmailSyncCursor;
use Symfony\Component\Uid\Uuid;

interface EmailSyncCursorRepositoryInterface
{
    public function find(Uuid $id): ?EmailSyncCursor;

    /**
     * El cursor de una carpeta concreta de un buzón. Es la clave natural:
     * `(cuenta, carpeta)`.
     */
    public function findForAccountFolder(Uuid $emailAccountId, string $folder): ?EmailSyncCursor;

    /**
     * @return list<EmailSyncCursor>
     */
    public function findForAccount(Uuid $emailAccountId): array;

    public function save(EmailSyncCursor $cursor, bool $flush = true): void;

    public function removeForAccount(Uuid $emailAccountId): int;
}
