<?php

declare(strict_types=1);

namespace App\Services\Application;

use App\Services\Domain\Entity\Service;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Pausa, reactiva o cancela un servicio.
 *
 * Cancelar no borra: el histórico de precios y eventos se conserva para poder
 * responder "¿cuánto pagaba y cuándo lo dejé?".
 */
final readonly class ChangeServiceStatus
{
    public function __construct(
        private ServiceRepositoryInterface $services,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function pause(Service $service, ?Uuid $actorUserId = null): void
    {
        $service->pause($actorUserId);
        $this->services->save($service);
        $this->log($service, 'paused');
    }

    public function resume(Service $service, ?Uuid $actorUserId = null): void
    {
        $service->resume($actorUserId);
        $this->services->save($service);
        $this->log($service, 'resumed');
    }

    public function cancel(Service $service, ?Uuid $actorUserId = null, ?DateTimeImmutable $at = null): void
    {
        $service->cancel($at ?? new DateTimeImmutable(), $actorUserId);
        $this->services->save($service);
        $this->log($service, 'cancelled');
    }

    private function log(Service $service, string $transition): void
    {
        $this->auditLogger->log(
            action: AuditAction::SERVICE_UPDATED,
            targetType: 'service',
            targetId: $service->getId()->toRfc4122(),
            metadata: ['name' => $service->getName(), 'transition' => $transition],
        );
    }
}
