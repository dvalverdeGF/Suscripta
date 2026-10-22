<?php

declare(strict_types=1);

namespace App\Shared\Application\Privacy;

use App\Shared\Domain\Repository\AuditLogRepositoryInterface;
use DateTimeImmutable;

/**
 * Purga el libro de auditoría caducado.
 *
 * El libro de auditoría es **append-only** mientras existe: nadie lo edita
 * ni lo borra, salvo esta purga. Dos años es el plazo habitual para dar
 * trazabilidad sin guardarlo para siempre.
 *
 * Vive en `Shared` porque el libro de auditoría es transversal: lo escriben
 * todos los módulos.
 */
final readonly class AuditLogPurger implements RetentionPurgerInterface
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLog,
        private PrivacySettings $settings,
    ) {
    }

    public function category(): string
    {
        return 'audit_log';
    }

    public function purge(DateTimeImmutable $now): int
    {
        return $this->auditLog->purgeOlderThan(
            $this->settings->auditLogThreshold($now),
            $this->settings->purgeBatchSize,
        );
    }
}
