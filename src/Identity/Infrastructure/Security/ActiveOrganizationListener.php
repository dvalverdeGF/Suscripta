<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Infrastructure\Doctrine\EventListener\TenantFilterListener;

use function is_string;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Resuelve la organización activa de la petición y la publica en TenantContext.
 *
 * Prioridad 500: después de que el firewall haya autenticado al usuario
 * (prioridad 8) y antes de que se ejecute cualquier controlador.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 500)]
final readonly class ActiveOrganizationListener
{
    public function __construct(
        private Security $security,
        private TenantContext $tenantContext,
        private OrganizationRepositoryInterface $organizations,
        private TenantFilterListener $tenantFilterListener,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        $organizationId = $this->resolveOrganizationId($user);

        if (null === $organizationId) {
            return;
        }

        $this->tenantContext->setOrganizationId($organizationId);
        $this->tenantFilterListener->synchronize();
    }

    private function resolveOrganizationId(User $user): ?Uuid
    {
        $memberships = $this->organizations->findForUser($user);

        if ([] === $memberships) {
            return null;
        }

        $requested = $this->requestedOrganizationId();

        if (null !== $requested) {
            foreach ($memberships as $membership) {
                if ($membership['organization']->getId()->equals($requested)) {
                    return $requested;
                }
            }
        }

        return $memberships[0]['organization']->getId();
    }

    private function requestedOrganizationId(): ?Uuid
    {
        $value = $_SESSION['active_organization_id'] ?? null;

        if (!is_string($value) || '' === $value) {
            return null;
        }

        return Uuid::isValid($value) ? Uuid::fromString($value) : null;
    }
}
