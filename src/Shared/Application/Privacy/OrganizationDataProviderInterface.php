<?php

declare(strict_types=1);

namespace App\Shared\Application\Privacy;

use Symfony\Component\Uid\Uuid;

/**
 * Aporta la sección de datos de un módulo a la exportación de una organización.
 *
 * El puerto lo declara quien **necesita** los datos (la exportación) y lo
 * implementa quien **sabe** dónde están (cada módulo). Así, exportar la
 * organización entera no obliga a que ningún módulo toque las entidades de
 * otro: cada uno publica lo suyo y el agregador lo junta (ARCHITECTURE.md §13.17).
 */
interface OrganizationDataProviderInterface
{
    /**
     * Nombre de la sección. Es la clave con la que aparecerá en el JSON
     * exportado, así que forma parte del contrato público de la API de
     * exportación.
     */
    public function section(): string;

    /**
     * Datos de la sección para la organización dada.
     *
     * @return array<string, mixed>
     */
    public function export(Uuid $organizationId): array;
}
