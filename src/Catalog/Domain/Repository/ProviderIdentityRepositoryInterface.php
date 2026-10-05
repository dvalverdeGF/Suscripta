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

    /**
     * Todas las identidades de un tipo, para los patrones.
     *
     * Los patrones de asunto y de adjunto no se pueden resolver con una
     * igualdad en SQL —son expresiones regulares—, así que se traen y se
     * evalúan en memoria. El catálogo de patrones es pequeño por diseño: un
     * patrón solo se añade cuando un dominio compartido lo hace necesario.
     *
     * @return list<ProviderIdentity>
     */
    public function findByType(ProviderIdentityType $type): array;

    public function save(ProviderIdentity $identity, bool $flush = true): void;
}
