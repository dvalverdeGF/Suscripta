<?php

declare(strict_types=1);

namespace App\Integrations\Domain\Repository;

use App\Integrations\Domain\Entity\Integration;
use Symfony\Component\Uid\Uuid;

/**
 * Acceso a la configuración de integraciones.
 */
interface IntegrationRepositoryInterface
{
    public function find(string $key): ?Integration;

    /**
     * Todas las integraciones, ordenadas por clave.
     *
     * @return list<Integration>
     */
    public function all(): array;

    /**
     * Integraciones habilitadas.
     *
     * @return list<Integration>
     */
    public function enabled(): array;

    public function save(Integration $integration, bool $flush = true): void;

    /**
     * Borra la configuración de integraciones de una organización.
     *
     * @return int número de integraciones eliminadas
     */
    public function removeForOrganization(Uuid $organizationId): int;

    /**
     * Las integraciones de una organización concreta.
     *
     * @return list<Integration>
     */
    public function findForOrganization(Uuid $organizationId): array;
}
