<?php

declare(strict_types=1);

namespace App\Subscriptions\Domain\Repository;

use App\Identity\Domain\Enum\OrganizationPlan;
use App\Subscriptions\Domain\Entity\Plan;
use Symfony\Component\Uid\Uuid;

/**
 * Acceso a los planes del catálogo comercial.
 *
 * No extiende ningún repositorio de inquilino: el catálogo es global (D-80),
 * así que aquí no hay `organization_id` que filtrar. Es la única consulta del
 * módulo que devuelve filas de todas las organizaciones, y por eso el panel que
 * la usa exige `ROLE_OPERATOR`.
 */
interface PlanRepositoryInterface
{
    public function find(Uuid $id): ?Plan;

    /**
     * El plan del catálogo que corresponde a un plan de la aplicación.
     */
    public function findByCode(OrganizationPlan $code): ?Plan;

    /**
     * Todos los planes, ordenados por posición.
     *
     * @param bool $onlyActive solo los publicados
     *
     * @return list<Plan>
     */
    public function findAll(bool $onlyActive = false): array;

    /**
     * Posición libre al final de la lista, para que un plan nuevo no nazca
     * empatado con el último.
     */
    public function nextPosition(): int;

    public function save(Plan $plan): void;

    public function remove(Plan $plan): void;
}
