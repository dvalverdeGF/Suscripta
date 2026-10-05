<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Repository;

use App\Mailbox\Domain\Entity\EmailSyncRun;
use Symfony\Component\Uid\Uuid;

interface EmailSyncRunRepositoryInterface
{
    public function find(Uuid $id): ?EmailSyncRun;

    public function findLatestForAccount(Uuid $emailAccountId): ?EmailSyncRun;

    /**
     * @return list<EmailSyncRun>
     */
    public function findRecentForAccount(Uuid $emailAccountId, int $limit = 10): array;

    public function save(EmailSyncRun $run, bool $flush = true): void;
}
