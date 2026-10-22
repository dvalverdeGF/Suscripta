<?php

declare(strict_types=1);

namespace App\Shared\UI\EventListener;

use App\Shared\Application\Entitlements\Capability;
use App\Shared\Domain\Exception\QuotaExceededException;

use function sprintf;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Convierte un límite de plan agotado en un aviso y no en un error (Fase 17).
 *
 * ## Por qué un listener y no un `catch` en cada controlador
 *
 * La cuota se comprueba en el **caso de uso**, porque es el caso de uso el
 * que tiene que respetarla (una API podría no pasar por el controlador). Pero
 * el caso de uso no sabe si lo está llamando un navegador. Si cada
 * controlador tuviera que envolver la llamada en un `try/catch`, se
 * olvidaría en alguno, y entonces el error saldría a pantalla como un 500.
 *
 * ## Dónde manda el aviso
 *
 * A la pantalla del módulo que ha pedido la operación: si no se puede añadir
 * un servicio, el sitio natural es el inventario de servicios. La redirección
 * se hace a una **ruta interna**, nunca a la cabecera `Referer`, que puede
 * apuntar a otro sitio.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class QuotaExceededListener
{
    /**
     * Pantalla a la que se vuelve según la capacidad que se ha agotado.
     *
     * @var array<string, string>
     */
    private const TARGETS = [
        Capability::SERVICES => 'app_services_index',
        Capability::EMAIL_ACCOUNTS => 'app_mail_accounts_index',
        Capability::DOCUMENTS => 'app_documents_index',
    ];

    public function __construct(
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof QuotaExceededException) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        // Sin petición (un comando, un worker) o en un formato que no es web
        // (la API) la excepción sigue su camino: quien la reciba la presenta
        // como corresponda.
        if (null === $request || 'html' !== $request->getRequestFormat()) {
            return;
        }

        if ($request->hasSession()) {
            $session = $request->getSession();

            // No toda sesión lleva la bolsa de avisos: una sesión de API o una
            // creada a mano puede no registrarla. Si no la tiene, el aviso se
            // pierde, pero la redirección —que es lo importante— sigue.
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('error', $this->message($exception));
            }
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate(self::TARGETS[$exception->capability] ?? 'app_dashboard'),
        ));
    }

    /**
     * El aviso dice qué pasa y qué se puede hacer.
     */
    private function message(QuotaExceededException $exception): string
    {
        if (null === $exception->limit) {
            return sprintf(
                'El plan %s no permite %s.',
                $exception->planLabel,
                $exception->label,
            );
        }

        return sprintf(
            'El plan %s permite %d %s y ya los has usado todos. Cambia de plan para poder añadir más.',
            $exception->planLabel,
            $exception->limit,
            $exception->label,
        );
    }
}
