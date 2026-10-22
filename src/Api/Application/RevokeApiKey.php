<?php

declare(strict_types=1);

namespace App\Api\Application;

use App\Api\Domain\Entity\ApiKey;
use App\Api\Domain\Repository\ApiKeyRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Revoca una clave de API.
 *
 * Revocar es **irreversible** a propósito: es la forma de cortar el acceso
 * cuando una clave se ha filtrado. Si el integrador la necesita otra vez,
 * emite una nueva; el panel deja de enseñar la revocada como «reutilizable».
 *
 * La operación es idempotente: revocar una clave ya revocada no falla, solo
 * no cambia nada. Un botón de «revocar» que se puede pulsar dos veces no debe
 * dar un error la segunda.
 */
final readonly class RevokeApiKey
{
    public function __construct(
        private ApiKeyRepositoryInterface $apiKeys,
        private TenantContext $tenantContext,
        private Clock $clock,
    ) {
    }

    public function __invoke(Uuid $apiKeyId): ApiKey
    {
        $organizationId = $this->tenantContext->requireOrganizationId();

        $apiKey = $this->apiKeys->find($apiKeyId);

        // La clave tiene que ser de esta organización. El filtro de Doctrine ya
        // lo garantiza, pero la comprobación explícita evita que un cambio
        // futuro en el filtro abra la puerta a revocar claves ajenas.
        if (null === $apiKey || !$apiKey->getOrganizationId()->equals($organizationId)) {
            throw new InvalidArgumentException('Esa clave de API no existe.');
        }

        $apiKey->revoke($this->clock->now());

        $this->apiKeys->save($apiKey);

        return $apiKey;
    }
}
