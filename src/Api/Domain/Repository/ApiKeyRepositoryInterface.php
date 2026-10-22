<?php

declare(strict_types=1);

namespace App\Api\Domain\Repository;

use App\Api\Domain\Entity\ApiKey;
use Symfony\Component\Uid\Uuid;

/**
 * Acceso a las claves de API de la organización activa.
 *
 * `findBySecretHash()` **no** filtra por organización a propósito: es la
 * consulta que resuelve la autenticación, y en ese momento todavía no se sabe
 * de qué organización es la clave. El filtro de Doctrine no restringe cuando
 * no hay organización activa, así que la búsqueda es exacta; la organización
 * se deduce de la propia clave.
 */
interface ApiKeyRepositoryInterface
{
    public function find(Uuid $id): ?ApiKey;

    public function findBySecretHash(string $secretHash): ?ApiKey;

    /**
     * @return list<ApiKey>
     */
    public function findForOrganization(Uuid $organizationId): array;

    public function save(ApiKey $apiKey, bool $flush = true): void;

    public function remove(ApiKey $apiKey, bool $flush = true): void;

    public function removeForOrganization(Uuid $organizationId): int;
}
