<?php

declare(strict_types=1);

namespace App\Discovery\Application;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * El usuario descarta una propuesta (ARCHITECTURE.md §13.11).
 *
 * Descartar no borra: la propuesta se queda en estado `ignored` con su motivo.
 * Es la materia prima del aprendizaje del buzón (§13.12): si el sistema propone
 * tres veces lo mismo y el usuario lo descarta tres veces, ese remitente deja
 * de ser candidato.
 */
final readonly class DismissDiscovery
{
    public function __construct(
        private DiscoveryRepositoryInterface $discoveries,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
    ) {
    }

    public function __invoke(Discovery $discovery, ?string $reason = null, ?Uuid $actorUserId = null): void
    {
        $this->tenantContext->requireOrganizationId();

        if (!$discovery->getStatus()->isPending()) {
            throw new InvalidArgumentException(sprintf('La propuesta ya está %s.', $discovery->getStatus()->label()));
        }

        $discovery->ignore(
            userId: $actorUserId ?? $this->tenantContext->requireOrganizationId(),
            at: $this->clock->now(),
            reason: $reason,
        );

        $this->discoveries->save($discovery);

        $this->auditLogger->log(
            action: AuditAction::DISCOVERY_DISMISSED,
            targetType: 'discovery',
            targetId: $discovery->getId()->toRfc4122(),
            metadata: ['type' => $discovery->getType()->value, 'reason' => $reason],
            actorUserId: $actorUserId,
        );
    }
}
