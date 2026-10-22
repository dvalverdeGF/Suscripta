<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Domain\Enum\OrganizationPlan;
use App\Shared\Application\Entitlements\Capability;
use App\Shared\Application\Entitlements\EntitlementSettings;
use App\Shared\Application\Entitlements\QuotaGuard;
use App\Shared\Application\OrganizationSettingsReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pantalla del plan y del consumo (Fase 17).
 *
 * ## Qué responde
 *
 * «¿Hasta dónde puedo llegar con lo que tengo?» La pantalla enseña, para cada
 * cosa que se cuenta, cuánto se ha usado y cuál es el techo del plan. Sin
 * esto el límite solo se conoce cuando se choca contra él, y entonces el
 * usuario ya ha hecho el trabajo que no le iban a dejar guardar.
 *
 * ## Por qué el plan no se cambia aquí
 *
 * Cambiar el plan es una decisión comercial: alguien paga y alguien cambia
 * el plan. En esta versión el cambio lo hace el operador de la instalación
 * con `app:plan:set`. Dejarlo en un botón sin cobro detrás sería regalar el
 * plan de pago.
 */
#[Route('/plan', name: 'app_plan_')]
#[IsGranted('ROLE_USER')]
final class PlanController extends AbstractController
{
    public function __construct(
        private readonly QuotaGuard $quota,
        private readonly EntitlementSettings $entitlements,
        private readonly OrganizationSettingsReader $organizations,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $organization = $this->organizations->current();

        if (null === $organization) {
            return $this->redirectToRoute('app_dashboard');
        }

        $plan = $organization->getPlan();

        return $this->render('backoffice/plan/index.html.twig', [
            'organization' => $organization,
            'plan' => $plan,
            'plan_label' => $this->entitlements->planLabel($plan),
            'statuses' => $this->quota->statuses(),
            'plans' => $this->planMatrix(),
            'ai_available' => $this->quota->allows(Capability::AI),
        ]);
    }

    /**
     * Límites de todos los planes, capacidad a capacidad.
     *
     * Se pinta la matriz entera y no solo el plan actual: el usuario tiene
     * que poder ver qué tiene y qué podría tener.
     *
     * @return list<array{value: string, label: string, limits: array<string, int|bool|null>}>
     */
    private function planMatrix(): array
    {
        $matrix = [];

        foreach ($this->entitlements->planNames() as $name) {
            $plan = OrganizationPlan::tryFrom($name);

            if (null === $plan) {
                continue;
            }

            $limits = [];

            foreach ($this->entitlements->capabilityNames() as $capability) {
                $limits[$capability] = $this->entitlements->valueFor($plan, $capability);
            }

            $matrix[] = [
                'value' => $name,
                'label' => $this->entitlements->planLabel($plan),
                'limits' => $limits,
            ];
        }

        return $matrix;
    }
}
