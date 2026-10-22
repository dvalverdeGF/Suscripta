<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Enum\OrganizationPlan;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;

/**
 * Cambia el plan de una organización dentro de una prueba (Fase 17).
 *
 * ## Por qué existe
 *
 * El plan FREE **no incluye IA**. Eso es intencionado: es lo que distingue un
 * plan del otro. Pero significa que cualquier prueba que necesite IA (presupuesto,
 * extracción, aplazamiento) tiene que arrancar desde un plan que la incluya.
 *
 * Sin este rasgo, esas pruebas dependerían del plan por defecto y fallarían
 * con un error que no explica nada («IA no disponible» en lugar de «tu
 * organización no puede usar IA»). Cambiar el plan al principio de la prueba
 * deja claro qué condición se está probando.
 *
 * ## Por qué guarda la organización y no solo su id
 *
 * La organización es la unidad de tenancy: el plan se guarda en ella. El
 * repositorio es la única forma de que el cambio llegue a la base de datos
 * dentro de la transacción de la prueba.
 */
trait SwitchesPlan
{
    /**
     * Pone la organización en el plan indicado.
     */
    protected function switchPlan(Organization $organization, OrganizationPlan $plan = OrganizationPlan::PRO): Organization
    {
        $organization->changePlan($plan);

        self::getContainer()->get(OrganizationRepositoryInterface::class)->save($organization);

        return $organization;
    }

    /**
     * Pone en el plan indicado la primera organización del usuario.
     *
     * Es atajo para el caso habitual: la organización que se crea al registrarse
     * el primer usuario de la prueba.
     */
    protected function switchPlanOfFirstMembership(User $user, OrganizationPlan $plan = OrganizationPlan::PRO): Organization
    {
        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($user);

        self::assertNotEmpty($memberships, 'El usuario no pertenece a ninguna organización.');

        return $this->switchPlan($memberships[0]['organization'], $plan);
    }
}
