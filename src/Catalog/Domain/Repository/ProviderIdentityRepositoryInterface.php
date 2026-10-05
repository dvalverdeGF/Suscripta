<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Entity\ProviderIdentity;
use App\Catalog\Domain\Enum\ProviderIdentityType;
use Symfony\Component\Uid\Uuid;

interface ProviderIdentityRepositoryInterface
{
    /**
     * Resolución directa: ¿a qué proveedor pertenece este dominio, esta
     * dirección o este patrón? Es la consulta caliente del pipeline
     * (ARCHITECTURE.md §13.6).
     */
    public function findByTypeAndValue(ProviderIdentityType $type, string $value): ?ProviderIdentity;

    /**
     * @return list<ProviderIdentity>
     */
    public function findForProvider(Uuid $providerId): array;

    public function save(ProviderIdentity $identity, bool $flush = true): void;
}
