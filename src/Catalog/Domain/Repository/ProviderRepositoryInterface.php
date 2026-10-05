<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Entity\Provider;
use Symfony\Component\Uid\Uuid;

interface ProviderRepositoryInterface
{
    public function find(Uuid $id): ?Provider;

    /**
     * Proveedores visibles para una organización: el catálogo global más los
     * propios del tenant.
     *
     * @return list<Provider>
     */
    public function findVisibleForOrganization(Uuid $organizationId): array;

    /**
     * @return array<string, Provider> indexado por id en forma de cadena
     */
    public function findVisibleIndexedById(Uuid $organizationId): array;

    public function findBySlug(Uuid $organizationId, string $slug): ?Provider;

    /**
     * Busca un proveedor del catálogo global (organization_id IS NULL).
     * Lo usa el sembrador del catálogo, que debe ser idempotente.
     */
    public function findGlobalBySlug(string $slug): ?Provider;

    public function save(Provider $provider, bool $flush = true): void;
}
