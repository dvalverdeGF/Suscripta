<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Repository;

use App\Mailbox\Domain\Entity\EmailSyncRun;
use App\Shared\Domain\ValueObject\DateRange;
use Symfony\Component\Uid\Uuid;

interface EmailSyncRunRepositoryInterface
{
    public function find(Uuid $id): ?EmailSyncRun;

    public function findLatestForAccount(Uuid $emailAccountId): ?EmailSyncRun;

    /**
     * @return list<EmailSyncRun>
     */
    public function findRecentForAccount(Uuid $emailAccountId, int $limit = 10): array;

    /**
     * Últimas sincronizaciones de toda la organización, de cualquier buzón.
     *
     * @return list<EmailSyncRun>
     */
    public function findRecentForOrganization(Uuid $organizationId, int $limit = 10): array;

    /**
     * Agregados de sincronización de la organización en un periodo.
     *
     * Se agregan en SQL y no en PHP: el histórico puede tener cientos de
     * miles de pasadas y traerlas todas para sumarlas en memoria es
     * exactamente lo que hace que un panel se caiga cuando el cliente está
     * mirando.
     *
     * @return array{runs: int, failed: int, seen: int, processed: int, skipped: int, backfilled: int, discoveries: int, averageDurationMs: int}
     */
    public function statsForOrganization(Uuid $organizationId, DateRange $period): array;

    public function save(EmailSyncRun $run, bool $flush = true): void;

    /**
     * Borra el historial de sincronización de una cuenta.
     *
     * @return int número de ejecuciones eliminadas
     */
    public function removeForAccount(Uuid $emailAccountId): int;
}
