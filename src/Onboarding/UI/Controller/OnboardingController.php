<?php

declare(strict_types=1);

namespace App\Onboarding\UI\Controller;

use App\Identity\Application\CompleteOnboarding;
use App\Identity\Domain\Entity\User;
use App\Onboarding\Application\BuildOnboardingChecklist;
use App\Onboarding\Application\Dto\OnboardingStep;

use function array_filter;
use function count;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La guía de bienvenida (Fase 17).
 *
 * ## Qué es
 *
 * La primera pantalla que ve alguien que acaba de entrar. No es un tutorial
 * con texto: es la lista de lo que falta para que el producto haga su
 * trabajo, con un botón para cada cosa.
 *
 * ## Por qué la termina una persona y no la aplicación entera
 *
 * El buzón se conecta desde la pantalla de buzones (tiene su propio
 * formulario, con prueba de conexión), no desde aquí. Esta pantalla **manda
 * allí** y comprueba después si se ha hecho. Por eso los pasos se calculan en
 * vivo y no se guardan.
 *
 * ## Por qué se puede saltar
 *
 * Porque hay gente que ya tiene sus datos y solo quiere mirar. Forzar a
 * pasar por el buzón convertiría la primera visita en un peaje. Se puede
 * terminar la guía en cualquier momento y volver al panel.
 */
#[Route('/onboarding', name: 'app_onboarding_')]
#[IsGranted('ROLE_USER')]
final class OnboardingController extends AbstractController
{
    public function __construct(
        private readonly BuildOnboardingChecklist $checklistBuilder,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $steps = ($this->checklistBuilder)();

        return $this->render('backoffice/onboarding/index.html.twig', [
            'steps' => $steps,
            'pending' => $this->pending($steps),
            'completed' => $this->user()?->isOnboardingCompleted() ?? false,
        ]);
    }

    /**
     * Termina la guía y vuelve al panel.
     *
     * Es un POST con token CSRF porque cambia el estado de la cuenta: no es un
     * enlace que pueda pulsar un tercero desde otro sitio.
     */
    #[Route('/finish', name: 'finish', methods: ['POST'])]
    public function finish(Request $request, CompleteOnboarding $completeOnboarding): Response
    {
        if (!$this->isCsrfTokenValid('finish_onboarding', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $user = $this->user();

        if (null !== $user) {
            $completeOnboarding($user);
        }

        $this->addFlash('success', 'Ya está. Esto es todo lo que hay que hacer para empezar.');

        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * Salta la guía y vuelve al panel.
     *
     * ## Por qué también marca la guía como vista
     *
     * Si «ahora no» no marcara nada, la siguiente visita volvería a la guía:
     * el enlace «ver el panel» de esta pantalla y el aviso que se pone al
     * entrar se pisarían, y el usuario quedaría atrapado en un bucle de
     * redirecciones.
     *
     * La diferencia con «hecho» es de fondo, no de forma: saltarla deja la
     * lista de pasos a medias, así que la guía vuelve a señalar lo que falta
     * cuando se abre desde el menú.
     */
    #[Route('/skip', name: 'skip', methods: ['POST'])]
    public function skip(Request $request, CompleteOnboarding $completeOnboarding): Response
    {
        if (!$this->isCsrfTokenValid('skip_onboarding', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $user = $this->user();

        if (null !== $user) {
            $completeOnboarding($user);
        }

        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * @param list<OnboardingStep> $steps
     */
    private function pending(array $steps): int
    {
        return count(array_filter($steps, static fn (OnboardingStep $step): bool => !$step->done));
    }

    private function user(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
