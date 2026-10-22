<?php

declare(strict_types=1);

namespace App\Observability\UI\Controller;

use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Notifications\Domain\Enum\AlertType;
use App\Observability\Application\BuildObservabilityReport;

use function array_key_exists;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Panel de observabilidad de producto (Fase 17).
 *
 * ## Qué se ve aquí
 *
 * Cuatro bloques, en el orden en que se depura un problema real:
 *
 * 1. **Sincronización**: si el correo entra y qué se ha hecho con él.
 * 2. **Extracción**: cómo se ha resuelto cada correo y cuánto ha costado.
 * 3. **IA**: llamadas, fallos, presupuesto y coste del periodo.
 * 4. **Avisos**: lo que el sistema le está pidiendo al usuario.
 *
 * ## Por qué no se fusiona con el panel de coste de IA
 *
 * El panel de coste (Fase 14) es una pregunta **financera** con su propio
 * periodo (el mes natural, el de la factura del proveedor). Este es una
 * pregunta **operativa** con periodos cortos (24 h, 7 días, 30 días). Unir
 * las dos obligaría a elegir un único periodo para dos preguntas que no
 * comparten ninguno, y el que pierda se queda sin respuesta.
 *
 * ## El periodo va en la URL
 *
 * `/observability?period=24h` es un enlace que se puede mandar a un
 * compañero tal cual. El estado en la sesión obligaría a ir a la pantalla
 * de todos modos.
 */
#[Route('/observability', name: 'app_observability')]
#[IsGranted('ROLE_USER')]
final class ObservabilityController extends AbstractController
{
    public function __construct(
        private readonly BuildObservabilityReport $builder,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $period = (string) $request->query->get('period', '7d');

        if (!array_key_exists($period, BuildObservabilityReport::availablePeriods())) {
            $period = '7d';
        }

        $report = $this->builder->forPeriod($period);

        return $this->render('backoffice/observability/index.html.twig', [
            'report' => $report,
            'period' => $period,
            'periods' => BuildObservabilityReport::availablePeriods(),
            'tierLabels' => $this->tierLabels(),
            'stateLabels' => $this->stateLabels(),
            'alertTypeLabels' => $this->alertTypeLabels(),
        ]);
    }

    /**
     * Nombre legible de cada nivel de extracción.
     *
     * @return array<string, string>
     */
    private function tierLabels(): array
    {
        $labels = [];

        foreach (ExtractionTier::cases() as $tier) {
            $labels[$tier->value] = $tier->label();
        }

        return $labels;
    }

    /**
     * Nombre legible de cada estado de procesamiento.
     *
     * @return array<string, string>
     */
    private function stateLabels(): array
    {
        $labels = [];

        foreach (MessageProcessingState::cases() as $state) {
            $labels[$state->value] = $state->label();
        }

        return $labels;
    }

    /**
     * Nombre legible de cada tipo de aviso.
     *
     * @return array<string, string>
     */
    private function alertTypeLabels(): array
    {
        $labels = [];

        foreach (AlertType::cases() as $type) {
            $labels[$type->value] = $type->label();
        }

        return $labels;
    }
}
