<?php

declare(strict_types=1);

namespace App\Ai\UI\Controller;

use App\Ai\Application\AiBudgetChangeHandlerInterface;
use App\Ai\Application\AiCostCalculator;
use App\Ai\Domain\Enum\AiPurpose;
use App\Integrations\Application\IntegrationRegistry;
use App\Mailbox\Domain\Enum\ExtractionTier;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Panel de coste y rendimiento de la IA (Fase 14).
 *
 * ## Qué se decide aquí
 *
 * Tres cosas, y las tres son decisiones del cliente:
 *
 * 1. **Cuánto puede gastar** este mes en IA (la pantalla de ajustes) y qué
 *    pasa cuando lo gasta todo.
 * 2. **En qué se va el dinero**: qué proveedor, qué necesidad, qué nivel.
 * 3. **Cuánta IA hace falta**: qué porcentaje de los correos se resuelve sin
 *    IA y cuántos escalan al modelo caro.
 *
 * ## Por qué una pantalla aparte y no dentro de los ajustes
 *
 * Porque los ajustes se rellenan una vez y el panel se mira todas las
 * semanas. Meter un informe dentro de un formulario lo convierte en un
 * formulario más: se deja de mirar en cuanto se ha configurado. Aquí el
 * informe está a un clic, siempre con el mes en curso delante.
 *
 * ## El mes es el periodo
 *
 * El panel enseña el mes natural en curso, que es el mismo periodo que
 * usa el presupuesto y el mismo que usan las facturas de los proveedores
 * (D-55).
 */
#[Route('/ai/cost', name: 'app_ai_cost')]
#[IsGranted('ROLE_USER')]
final class AiCostController extends AbstractController
{
    public function __construct(
        private readonly AiCostCalculator $calculator,
        private readonly AiBudgetChangeHandlerInterface $budgetChange,
        private readonly IntegrationRegistry $registry,
    ) {
    }

    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('release_deferred_messages', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Token CSRF no válido.');
            }

            $released = $this->budgetChange->onBudgetRaised();

            $this->addFlash(
                'success',
                0 === $released
                    ? 'No hay correos aplazados, o el presupuesto sigue agotado.'
                    : sprintf('Se han puesto en cola %d correos aplazados.', $released),
            );

            return $this->redirectToRoute('app_ai_cost');
        }

        $report = $this->calculator->currentMonth();

        return $this->render('backoffice/ai/cost.html.twig', [
            'report' => $report,
            'budget' => $report->budget,
            'providers' => $this->providerNames(),
            'purposes' => $this->purposeLabels(),
            'tiers' => $this->tierLabels(),
        ]);
    }

    /**
     * Nombre legible de los proveedores conocidos.
     *
     * Se sacan del registro de integraciones y no de una lista escrita aquí:
     * si mañana se añade un proveedor, el panel lo enseña sin tocar nada.
     *
     * @return array<string, string>
     */
    private function providerNames(): array
    {
        $names = [];

        foreach ($this->registry->aiProviders() as $definition) {
            $names[$definition->key] = $definition->label;
        }

        return $names;
    }

    /**
     * Nombre legible de cada necesidad de IA.
     *
     * @return array<string, string>
     */
    private function purposeLabels(): array
    {
        $labels = [];

        foreach (AiPurpose::cases() as $purpose) {
            $labels[$purpose->value] = $purpose->label();
        }

        return $labels;
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
}
