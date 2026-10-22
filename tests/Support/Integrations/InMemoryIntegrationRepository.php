<?php

declare(strict_types=1);

namespace App\Tests\Support\Integrations;

use App\Integrations\Domain\Entity\Integration;
use App\Integrations\Domain\Repository\IntegrationRepositoryInterface;

use function array_filter;
use function array_values;

use Symfony\Component\Uid\Uuid;

/**
 * Repositorio de integraciones en memoria.
 *
 * Guarda las **mismas instancias** que recibe, para que un test pueda
 * comprobar después qué se ha guardado (y, sobre todo, que el secreto no
 * aparece en claro).
 */
final class InMemoryIntegrationRepository implements IntegrationRepositoryInterface
{
    /**
     * @var array<string, Integration>
     */
    private array $integrations = [];

    public function find(string $key): ?Integration
    {
        return $this->integrations[$key] ?? null;
    }

    public function all(): array
    {
        return array_values($this->integrations);
    }

    public function enabled(): array
    {
        return array_values(array_filter(
            $this->integrations,
            static fn (Integration $integration): bool => $integration->isEnabled(),
        ));
    }

    public function save(Integration $integration, bool $flush = true): void
    {
        $this->integrations[$integration->getKey()] = $integration;
    }

    /**
     * Añade una integración ya construida, sin pasar por el escritor.
     */
    public function add(Integration $integration): void
    {
        $this->integrations[$integration->getKey()] = $integration;
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        $removed = 0;

        foreach ($this->integrations as $key => $integration) {
            if (!$integration->getOrganizationId()->equals($organizationId)) {
                continue;
            }

            unset($this->integrations[$key]);
            ++$removed;
        }

        return $removed;
    }

    /**
     * @return list<Integration>
     */
    public function findForOrganization(Uuid $organizationId): array
    {
        return array_values(array_filter(
            $this->integrations,
            static fn (Integration $integration): bool => $integration->getOrganizationId()->equals($organizationId),
        ));
    }
}
