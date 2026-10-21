<?php

declare(strict_types=1);

namespace App\Ai\UI\Controller;

use App\Ai\Application\AiPurposeSelector;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Integrations\Application\IntegrationRegistry;

use function is_string;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Elección de IA por necesidad (Fase 11).
 *
 * ## Qué se decide aquí
 *
 * Para cada necesidad (`AiPurpose`) se elige **qué proveedor** se usa. La
 * elección se guarda en los ajustes de la organización, así que es por
 * cliente: dos organizaciones pueden usar proveedores distintos para la misma
 * necesidad, y es lo correcto, porque cada una paga su factura.
 *
 * ## La IA está desactivada por defecto (D-08)
 *
 * El interruptor de activación es la primera decisión de la pantalla, no un
 * detalle: sin él, ninguna llamada se hace, aunque haya una clave configurada.
 * Se explica con claridad qué implica activarla, porque es la decisión que
 * tiene consecuencias económicas.
 */
#[Route('/ai', name: 'app_ai_')]
#[IsGranted('ROLE_USER')]
final class AiSettingsController extends AbstractController
{
    public function __construct(
        private readonly AiPurposeSelector $selector,
        private readonly IntegrationRegistry $registry,
    ) {
    }

    #[Route('', name: 'settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('save_ai_settings', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Token CSRF no válido.');
            }

            $this->selector->setEnabled($request->request->has('ai_enabled'));
            $this->selector->saveChoices($this->choices($request));

            $this->addFlash('success', 'Se ha guardado la configuración de IA.');

            return $this->redirectToRoute('app_ai_settings');
        }

        $choices = $this->selector->choices();

        return $this->render('backoffice/ai/settings.html.twig', [
            'purposes' => AiPurpose::cases(),
            'providers' => $this->registry->aiProviders(),
            'choices' => $choices,
            'enabled' => $this->selector->isEnabled(),
            'tiers' => AiTier::cases(),
        ]);
    }

    /**
     * Elección enviada, filtrada por las necesidades y los proveedores que
     * existen.
     *
     * Un `purpose` o un `provider` inventado en el POST se ignora: la lista
     * válida la pone el código, no el formulario.
     *
     * @return array<string, string>
     */
    private function choices(Request $request): array
    {
        $knownPurposes = [];

        foreach (AiPurpose::cases() as $purpose) {
            $knownPurposes[$purpose->value] = true;
        }

        $knownProviders = [];

        foreach ($this->registry->aiProviders() as $definition) {
            $knownProviders[$definition->key] = true;
        }

        $choices = [];

        foreach ($request->request->all('provider') as $purpose => $key) {
            if (!is_string($key) || !isset($knownPurposes[(string) $purpose]) || !isset($knownProviders[$key])) {
                continue;
            }

            $choices[(string) $purpose] = $key;
        }

        return $choices;
    }
}
