<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;

/**
 * Lectura y escritura de los ajustes de la organización activa.
 *
 * `Organization.settings` es un `json` genérico a propósito: no se sabe de
 * antemano qué ajustes vai a tener la organización, y una tabla con una
 * columna por ajuste obligaría a una migración cada vez que se añade uno.
 *
 * Se encapsula aquí para que el acceso a ese `json` no esté esparcido por la
 * aplicación y para que exista **un solo** sitio donde se resuelve la
 * organización activa.
 */
final readonly class OrganizationSettingsReader
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * Organization activa.
     *
     * `null` cuando no hay ninguna: en un proceso de sistema (un comando de
     * mantenimiento, un worker sin sesión) puede no haberla.
     */
    public function current(): ?Organization
    {
        $organizationId = $this->tenantContext->getOrganizationId();

        if (null === $organizationId) {
            return null;
        }

        return $this->organizations->find($organizationId);
    }

    /**
     * Valor de un ajuste, o el valor por defecto.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->current()?->getSetting($key, $default) ?? $default;
    }

    /**
     * Escribe un ajuste.
     *
     * No hace nada si no hay organización activa: escribir en la nada es un
     * error silencioso que se descubre tarde, así que se deja pasar y quien
     * llama decide.
     */
    public function set(string $key, mixed $value): void
    {
        $organization = $this->current();

        if (null === $organization) {
            return;
        }

        $organization->setSetting($key, $value);
        $this->organizations->save($organization);
    }

    /**
     * Todos los ajustes de la organización activa.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $organization = $this->current();

        if (null === $organization) {
            return [];
        }

        return $organization->getSettings();
    }
}
