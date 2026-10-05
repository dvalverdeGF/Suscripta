<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Application\TenantFilterSynchronizerInterface;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilter;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Activa el filtro `tenant` y le inyecta la organización activa.
 *
 * El filtro se activa siempre, incluso sin organización: en ese caso no
 * restringe (ver TenantFilter), pero queda listo para que el siguiente cambio
 * de contexto surta efecto sin tener que acordarse de activarlo.
 */
final readonly class DoctrineTenantFilterSynchronizer implements TenantFilterSynchronizerInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function synchronize(?Uuid $organizationId): void
    {
        $filters = $this->entityManager->getFilters();

        if (!$filters->isEnabled(TenantFilter::NAME)) {
            $filters->enable(TenantFilter::NAME);
        }

        $filters->getFilter(TenantFilter::NAME)->setParameter(
            'organizationId',
            $organizationId?->toRfc4122() ?? '',
            Types::STRING,
        );
    }
}
