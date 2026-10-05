<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Filter;

use App\Shared\Domain\Contract\TenantAwareInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

use function sprintf;

/**
 * Añade `organization_id = :organizationId` a toda consulta sobre entidades
 * que implementan TenantAwareInterface.
 *
 * Si no hay organización activa el filtro no restringe: eso solo ocurre en
 * procesos de sistema (migraciones, purgas, tareas de administración), que
 * deben ser explícitos al desactivarlo.
 */
final class TenantFilter extends SQLFilter
{
    public const NAME = 'tenant';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->getReflectionClass()->implementsInterface(TenantAwareInterface::class)) {
            return '';
        }

        $organizationId = $this->getParameter('organizationId');

        if ('' === $organizationId) {
            return '';
        }

        return sprintf('%s.organization_id = %s', $targetTableAlias, $organizationId);
    }
}
