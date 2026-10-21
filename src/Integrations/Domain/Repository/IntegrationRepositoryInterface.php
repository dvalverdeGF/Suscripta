<?php

declare(strict_types=1);

namespace App\Integrations\Domain\Repository;

use App\Integrations\Domain\Entity\Integration;

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
}
