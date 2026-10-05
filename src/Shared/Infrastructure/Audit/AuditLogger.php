<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Entity\AuditLog;
use App\Shared\Domain\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Escribe en `audit_log` sin interrumpir la operación de negocio.
 *
 * La auditoría es un requisito legal, no una funcionalidad: si falla, se
 * registra el fallo y se continúa. Nunca debe tumbar un alta de usuario ni una
 * sincronización de correo.
 */
final readonly class AuditLogger implements AuditLoggerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private Security $security,
        private RequestStack $requestStack,
    ) {
    }

    public function log(
        AuditAction $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $metadata = [],
        ?Uuid $organizationId = null,
        ?Uuid $actorUserId = null,
    ): void {
        $request = $this->requestStack->getCurrentRequest();

        $entry = new AuditLog(
            action: $action,
            organizationId: $organizationId ?? $this->tenantContext->getOrganizationId(),
            actorUserId: $actorUserId ?? $this->currentUserId(),
            actorType: $this->resolveActorType(),
            targetType: $targetType,
            targetId: $targetId,
            metadata: $metadata,
            ipAddress: $request?->getClientIp(),
            userAgent: $request?->headers->get('User-Agent'),
        );

        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    private function currentUserId(): ?Uuid
    {
        $user = $this->security->getUser();

        if ($user instanceof \App\Identity\Domain\Entity\User) {
            return $user->getId();
        }

        return null;
    }

    private function resolveActorType(): string
    {
        if (null !== $this->security->getUser()) {
            return 'user';
        }

        return null === $this->requestStack->getCurrentRequest() ? 'system' : 'anonymous';
    }
}
