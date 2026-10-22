<?php

declare(strict_types=1);

namespace App\Shared\Domain\Repository;

use App\Shared\Domain\Entity\AuditLog;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Acceso al libro de auditoría.
 *
 * Es **append-only** mientras exista: nadie edita ni borra entradas, solo se
 * purgan cuando pasan su plazo de retención (SECURITY.md §6). Por eso la
 * interfaz no expone `save` ni `remove`: quien escribe es el `AuditLogger`.
 */
interface AuditLogRepositoryInterface
{
    /**
     * @return list<AuditLog>
     */
    public function findRecent(int $limit = 50): array;

    public function countForOrganization(Uuid $organizationId): int;

    /**
     * Purga las entradas anteriores al umbral.
     *
     * @return int número de entradas eliminadas
     */
    public function purgeOlderThan(DateTimeImmutable $threshold, int $limit = 1000): int;
}
