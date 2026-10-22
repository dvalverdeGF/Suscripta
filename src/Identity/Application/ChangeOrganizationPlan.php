<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Enum\OrganizationPlan;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function in_array;
use function sprintf;

/**
 * Cambia el plan de una organización (Fase 17, D-65).
 *
 * ## Por qué un caso de uso y no un `UPDATE` en el comando
 *
 * El cambio de plan no es un parche en la base de datos: es una decisión de
 * negocio con consecuencias (aparece o desaparece la inteligencia artificial,
 * cambia el techo de servicios, de buzones y de documentos). Por eso pasa por
 * un caso de uso que:
 *
 * 1. Comprueba que el plan de destino **existe** en la configuración de
 *    límites. Un plan que no está en `entitlements.yaml` no es un plan: es
 *    una errata, y aceptarla dejaría a la organización sin límites definidos.
 * 2. Deja constancia en el libro de auditoría, con el plan anterior y el
 *    nuevo, porque es un cambio de las condiciones comerciales.
 *
 * ## Lo que NO hace
 *
 * No borra nada ni avisa a nadie. Bajar de un plan a otro **no** elimina lo
 * que ya existe: la organización se queda con sus servicios y con sus
 * documentos, pero no puede añadir más hasta que vuelva a caber. Quitar
 * datos automáticamente al bajar de plan sería una sorpresa muy unpleasant.
 */
final readonly class ChangeOrganizationPlan
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    /**
     * @param list<string> $knownPlans planes declarados en la configuración de
     *                                 límites; un plan que no esté aquí no existe
     */
    public function __invoke(
        Organization $organization,
        OrganizationPlan $plan,
        array $knownPlans = [],
    ): Organization {
        if ([] !== $knownPlans && !in_array($plan->value, $knownPlans, true)) {
            throw new InvalidArgumentException(sprintf('El plan "%s" no está definido en la configuración de límites.', $plan->value));
        }

        $previous = $organization->getPlan();

        if ($previous === $plan) {
            return $organization;
        }

        $organization->changePlan($plan);

        $this->organizations->save($organization);

        $this->auditLogger->log(
            action: AuditAction::ORGANIZATION_PLAN_CHANGED,
            targetType: 'organization',
            targetId: $organization->getId()->toRfc4122(),
            metadata: [
                'from' => $previous->value,
                'to' => $plan->value,
            ],
            organizationId: $organization->getId(),
        );

        return $organization;
    }
}
