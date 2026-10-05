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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Resuelve la organización activa de la petición y la publica en TenantContext.
 *
 * Prioridad 0: en `kernel.request` los números más altos se ejecutan antes, así
 * que hay que quedar por debajo de `AbstractSessionListener` (128, que instala la
 * factoría de sesión) y del firewall (8, que restaura el token). Con una
 * prioridad mayor el usuario todavía no está autenticado y el contexto queda
 * vacío. Sigue ejecutándose antes que cualquier controlador.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
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

        $organizationId = $this->resolveOrganizationId($user, $event->getRequest());

        if (null === $organizationId) {
            return;
        }

        $this->tenantContext->setOrganizationId($organizationId);
        $this->tenantFilterListener->synchronize();
    }

    private function resolveOrganizationId(User $user, Request $request): ?Uuid
    {
        $memberships = $this->organizations->findForUser($user);

        if ([] === $memberships) {
            return null;
        }

        $requested = $this->requestedOrganizationId($request);

        if (null !== $requested) {
            foreach ($memberships as $membership) {
                if ($membership['organization']->getId()->equals($requested)) {
                    return $requested;
                }
            }
        }

        return $memberships[0]['organization']->getId();
    }

    /**
     * La preferencia vive en la sesión, no en el usuario: es navegación, no un
     * dato de la cuenta. Se lee a través del servicio de sesión y nunca de
     * `$_SESSION`, porque Symfony guarda los atributos dentro de su propio
     * *bag* (`_sf2_attributes`) y no en la raíz de la superglobal.
     */
    private function requestedOrganizationId(Request $request): ?Uuid
    {
        if (!$request->hasSession()) {
            return null;
        }

        $value = $request->getSession()->get('active_organization_id');

        if (!is_string($value) || '' === $value) {
            return null;
        }

        return Uuid::isValid($value) ? Uuid::fromString($value) : null;
    }
}
