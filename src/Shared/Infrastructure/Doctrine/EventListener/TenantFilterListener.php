<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\EventListener;

use App\Shared\Application\TenantContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Deja el filtro `tenant` activo desde el primer momento de la petición.
 *
 * Se ejecuta antes de cualquier consulta. La organización activa la publica
 * después ActiveOrganizationListener, que ya no tiene que acordarse de
 * sincronizar: `TenantContext` lo hace en cada cambio de contexto.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
final readonly class TenantFilterListener
{
    public function __construct(private TenantContext $tenantContext)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $this->synchronize();
    }

    public function synchronize(): void
    {
        $this->tenantContext->setOrganizationId($this->tenantContext->getOrganizationId());
    }
}
