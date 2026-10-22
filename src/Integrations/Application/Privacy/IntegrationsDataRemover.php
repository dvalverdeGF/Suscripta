<?php

declare(strict_types=1);

namespace App\Integrations\Application\Privacy;

use App\Integrations\Domain\Repository\IntegrationRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataRemoverInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Borra las integraciones de una organización, con sus credenciales.
 *
 * Las credenciales cifradas se van con la fila: no queda ni el texto
 * cifrado. Es lo que exige SECURITY.md §6 para cualquier secreto almacenado.
 */
final readonly class IntegrationsDataRemover implements OrganizationDataRemoverInterface
{
    public function __construct(
        private IntegrationRepositoryInterface $integrations,
    ) {
    }

    public function module(): string
    {
        return 'integrations';
    }

    /**
     * Las integraciones son configuración: se van cuando ya nadie las usa.
     */
    public function priority(): int
    {
        return 60;
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        return $this->integrations->removeForOrganization($organizationId);
    }
}
