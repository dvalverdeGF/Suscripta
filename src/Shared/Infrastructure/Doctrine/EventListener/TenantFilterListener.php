<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\EventListener;

use App\Shared\Application\TenantContext;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilter;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Activa el filtro `tenant` y le inyecta la organización activa.
 *
 * Se ejecuta antes de cualquier consulta: en cada petición HTTP y también al
 * arrancar un mensaje de Messenger (ver TenantContextMiddleware).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
final readonly class TenantFilterListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $this->synchronize();
    }

    public function synchronize(): void
    {
        $filters = $this->entityManager->getFilters();

        if (!$filters->isEnabled(TenantFilter::NAME)) {
            $filters->enable(TenantFilter::NAME);
        }

        $filter = $filters->getFilter(TenantFilter::NAME);
        $organizationId = $this->tenantContext->getOrganizationId();

        $filter->setParameter(
            'organizationId',
            $organizationId?->toRfc4122() ?? '',
            Types::STRING,
        );
    }
}
