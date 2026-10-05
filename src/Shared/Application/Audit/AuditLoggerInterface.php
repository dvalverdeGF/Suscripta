<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit;

use App\Shared\Domain\Enum\AuditAction;
use Symfony\Component\Uid\Uuid;

/**
 * Punto único de escritura del libro de auditoría.
 *
 * Los casos de uso no construyen `AuditLog` a mano: piden al registrador que
 * anote la acción, y este resuelve el actor y la organización activa. Así no se
 * puede olvidar rellenar el contexto.
 */
interface AuditLoggerInterface
{
    /**
     * @param array<string, scalar|null> $metadata
     */
    public function log(
        AuditAction $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $metadata = [],
        ?Uuid $organizationId = null,
        ?Uuid $actorUserId = null,
    ): void;
}
