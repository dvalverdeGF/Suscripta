<?php

declare(strict_types=1);

namespace App\Services\Application;

use App\Services\Domain\Entity\Service;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;

/**
 * Borra un servicio y todo su historial.
 *
 * Es una operación destructiva y por eso queda auditada. Para "dejar de pagar"
 * lo correcto es cancelar, no borrar: borrar pierde el histórico de precios.
 */
final readonly class DeleteService
{
    public function __construct(
        private ServiceRepositoryInterface $services,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(Service $service): void
    {
        $id = $service->getId()->toRfc4122();
        $name = $service->getName();

        $this->services->remove($service);

        $this->auditLogger->log(
            action: AuditAction::SERVICE_DELETED,
            targetType: 'service',
            targetId: $id,
            metadata: ['name' => $name],
        );
    }
}
