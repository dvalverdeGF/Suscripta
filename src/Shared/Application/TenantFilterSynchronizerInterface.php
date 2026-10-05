<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Publica la organización activa en el filtro de Doctrine.
 *
 * Existe para que `TenantContext` pueda cambiar de organización sin depender de
 * Doctrine: la capa de aplicación declara la intención y la infraestructura la
 * aplica. Sin esto, cambiar de organización en un comando o en un worker no
 * tendría ningún efecto sobre las consultas y se leerían datos de otras
 * organizaciones.
 */
interface TenantFilterSynchronizerInterface
{
    public function synchronize(?Uuid $organizationId): void;
}
