<?php

declare(strict_types=1);

namespace App\Onboarding\UI\EventListener;

use App\Identity\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Manda a la guía de bienvenida a quien todavía no la ha terminado (Fase 17).
 *
 * ## Dónde redirige
 *
 * Solo desde **el panel**. El resto de pantallas siguen siendo accesibles
 * durante la guía, y esto es deliberado: el objetivo es que el primer
 * impacto sea una explicación, no un muro. Además, si la guía bloquease todo,
 * un cliente que ya tiene sus datos cargados y solo quiere consultar una
 * factura no podría ni mirar su factura.
 *
 * ## Por qué un listener y no el controlador del panel
 *
 * Porque la regla no es «el panel decide», es «en esta aplicación, quien no
 * ha terminado la guía no se queda en el panel». Si la regla viviera en el
 * controlador, cualquier otra entrada al panel (un enlace de correo, una
 * redirección posterior a una acción) dejaría de aplicarla.
 *
 * ## Qué no toca
 *
 * - Las peticiones que no son de navegador (la API): ahí no hay a quién
 *   redirigir.
 * - El propio proceso de la guía, la verificación del correo y la salida: si
 *   no, se podría quedar en un bucle.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final readonly class OnboardingRedirectListener
{
    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ('html' !== $request->getRequestFormat()) {
            return;
        }

        if ('app_dashboard' !== $request->attributes->get('_route')) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        if ($user->isOnboardingCompleted()) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_onboarding_index'),
        ));
    }
}
