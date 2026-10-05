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

    /**
     * El sincronizador es opcional para que el contexto se pueda construir sin
     * infraestructura (pruebas unitarias con repositorios en memoria). En la
     * aplicación siempre llega inyectado.
     */
    public function __construct(private readonly ?TenantFilterSynchronizerInterface $synchronizer = null)
    {
    }

    public function getOrganizationId(): ?Uuid
    {
        return $this->organizationId;
    }

    public function setOrganizationId(?Uuid $organizationId): void
    {
        $this->organizationId = $organizationId;
        $this->synchronizer?->synchronize($organizationId);
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
     *
     * Cambiar el contexto **no basta**: hay que publicarlo en el filtro de
     * Doctrine, o las consultas del bloque seguirían viendo todas las
     * organizaciones. Por eso se sincroniza al entrar y al salir.
     */
    public function runAs(Uuid $organizationId, callable $callback): mixed
    {
        $previous = $this->organizationId;
        $this->setOrganizationId($organizationId);

        try {
            return $callback();
        } finally {
            $this->setOrganizationId($previous);
        }
    }
}
