<?php

declare(strict_types=1);

namespace App\Shared\Domain\Contract;

use Symfony\Component\Uid\Uuid;

/**
 * Marca las entidades que pertenecen a una organización.
 *
 * Toda entidad que implemente esta interfaz queda cubierta por el filtro de
 * Doctrine `tenant` (DECISIONS.md D-11). El aislamiento entre organizaciones no
 * depende de que el programador recuerde añadir un `WHERE`.
 */
interface TenantAwareInterface
{
    public function getOrganizationId(): Uuid;

    public function setOrganizationId(Uuid $organizationId): void;
}
