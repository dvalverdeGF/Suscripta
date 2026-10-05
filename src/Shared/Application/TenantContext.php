<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Organización activa durante la petición o el mensaje que se está procesando.
 *
 * Es el único punto desde el que el filtro de Doctrine obtiene el identificador
 * de organización. Nunca se pasa el `organizationId` a mano en las consultas.
 */
final class TenantContext
{
    private ?Uuid $organizationId = null;

    public function getOrganizationId(): ?Uuid
    {
        return $this->organizationId;
    }

    public function setOrganizationId(?Uuid $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    public function hasOrganization(): bool
    {
        return null !== $this->organizationId;
    }

    public function requireOrganizationId(): Uuid
    {
        if (null === $this->organizationId) {
            throw new InvalidArgumentException('No hay organización activa en el contexto.');
        }

        return $this->organizationId;
    }

    /**
     * Ejecuta un bloque con una organización concreta y restaura la anterior.
     * Necesario en workers y comandos, donde el contexto no viene de la sesión.
     */
    public function runAs(Uuid $organizationId, callable $callback): mixed
    {
        $previous = $this->organizationId;
        $this->organizationId = $organizationId;

        try {
            return $callback();
        } finally {
            $this->organizationId = $previous;
        }
    }
}
