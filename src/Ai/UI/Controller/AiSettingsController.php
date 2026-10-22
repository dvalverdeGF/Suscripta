<?php

declare(strict_types=1);

namespace App\Ai\UI\Controller;

use App\Ai\Application\AiBudgetChangeHandlerInterface;
use App\Ai\Application\AiBudgetGuard;
use App\Ai\Application\AiPurposeSelector;
use App\Ai\Domain\Enum\AiBudgetMode;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Integrations\Application\IntegrationRegistry;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;

use function is_string;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

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
        private readonly AiBudgetGuard $budget,
        private readonly AiBudgetChangeHandlerInterface $budgetChange,
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

            $this->saveBudget($request);

            $this->addFlash('success', 'Se ha guardado la configuración de IA.');

            return $this->redirectToRoute('app_ai_settings');
        }

        $choices = $this->selector->choices();
        $status = $this->budget->status();

        return $this->render('backoffice/ai/settings.html.twig', [
            'purposes' => AiPurpose::cases(),
            'providers' => $this->registry->aiProviders(),
            'choices' => $choices,
            'enabled' => $this->selector->isEnabled(),
            'tiers' => AiTier::cases(),
            'budget' => $status,
            'budget_modes' => AiBudgetMode::cases(),
            'budget_limit' => $status->isUnlimited() ? '' : Money::of($status->limitMinor, Currency::EUR)->format(false),
        ]);
    }

    /**
     * Guarda el tope y el modo del presupuesto (Fase 14).
     *
     * Cuando el tope **sube**, se sueltan los correos que estaban aplazados:
     * el cliente ha tomado una decisión y espera que el sistema la aplique, no
     * que espere al reinicio del mes.
     */
    private function saveBudget(Request $request): void
    {
        $limit = $this->parseLimit((string) $request->request->get('budget_limit', ''));
        $mode = $this->parseMode((string) $request->request->get('budget_mode', ''));

        if (null === $limit) {
            $this->addFlash('danger', 'El tope del presupuesto no es un importe válido.');

            return;
        }

        $before = $this->budget->limitMinor();
        $this->budget->configure($limit, $mode);

        if ($limit > $before) {
            $released = $this->budgetChange->onBudgetRaised();

            if ($released > 0) {
                $this->addFlash('success', sprintf(
                    'Se han puesto en cola %d correos que estaban aplazados por presupuesto.',
                    $released,
                ));
            }
        }
    }

    /**
     * Interpreta el tope enviado por el formulario, en euros.
     *
     * Devuelve `null` cuando el texto no es un importe válido. Un campo vacío
     * significa «sin tope», que es `0`.
     */
    private function parseLimit(string $value): ?int
    {
        $value = trim($value);

        if ('' === $value) {
            return 0;
        }

        try {
            $money = Money::fromDecimalString($value, Currency::EUR);
        } catch (Throwable) {
            return null;
        }

        if ($money->isNegative()) {
            return null;
        }

        return $money->amountMinor;
    }

    /**
     * Interpreta el modo enviado por el formulario.
     *
     * Un modo que no existe se ignora y se queda el que había: el campo lo
     * pone el código, no el cliente.
     */
    private function parseMode(string $value): AiBudgetMode
    {
        $mode = AiBudgetMode::tryFrom($value);

        return $mode ?? $this->budget->mode();
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
