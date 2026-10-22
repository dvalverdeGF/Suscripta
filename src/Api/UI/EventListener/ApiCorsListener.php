<?php

declare(strict_types=1);

namespace App\Api\UI\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function str_starts_with;
use function trim;

/**
 * CORS de la API pública (Fase 18).
 *
 * ## Para qué
 *
 * La API se consume desde el navegador (un panel de control en el cliente, un
 * Excel con Power Query, un script de una página) sin pasar por su servidor.
 * El navegador exige que el servidor diga explícitamente que puede: sin las
 * cabeceras `Access-Control-*`, la respuesta llega al código pero el
 * navegador la bloquea.
 *
 * ## El preflight va antes del firewall
 *
 * El preflight (`OPTIONS`) lo manda el navegador **sin credenciales**: no
 * puede mandarlas en un preflight, porque la petición preflight no es la
 * petición real. Si el firewall exigiera la clave en el preflight, el
 * navegador recibiría un `401` y la petición real nunca saldría del
 * navegador. Por eso este listener se ejecuta antes que el firewall
 * (prioridad 9 frente a la 8 del firewall) y responde al `OPTIONS` con un
 * `204` sin más.
 *
 * ## Los orígenes se configuran, no se permite cualquiera
 *
 * `Access-Control-Allow-Origin: *` dejaría que **cualquier** página del
 * mundo pudiera leer los datos del cliente con la clave que ese navegador
 * tenga guardada. Los orígenes permitidos son una lista explícita en la
 * configuración; si un origen no está en la lista, no se manda la cabecera y
 * el navegador lo bloquea. Es la respuesta correcta: negar por omisión.
 *
 * ## Sin credenciales
 *
 * No se manda `Access-Control-Allow-Credentials`. La API se autentica con una
 * cabecera, no con cookies, y permitir credenciales haría que el navegador
 * mande cookies a un servidor público: no hace falta y abre una puerta
 * innecesaria.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final class ApiCorsListener
{
    private const METHODS = 'GET, POST, PATCH, PUT, DELETE, OPTIONS';

    private const HEADERS = 'Authorization, Content-Type, X-Api-Key, X-Requested-With';

    private const EXPOSED = 'X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After';

    /**
     * @var list<string>
     */
    private readonly array $allowedOrigins;

    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(array $allowedOrigins)
    {
        // La lista puede venir del entorno como JSON: se limpia aquí para que
        // un origen vacío o con espacios no llegue a compararse.
        $this->allowedOrigins = array_values(array_filter(
            array_map(static fn (string $origin): string => trim($origin), $allowedOrigins),
            static fn (string $origin): bool => '' !== $origin,
        ));
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->isApiRequest($request)) {
            return;
        }

        if ('OPTIONS' !== $request->getMethod()) {
            return;
        }

        $origin = $this->origin($request);

        if (null === $origin) {
            return;
        }

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $this->decorate($response, $origin);

        $event->setResponse($response);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->isApiRequest($request)) {
            return;
        }

        $origin = $this->origin($request);

        if (null === $origin) {
            return;
        }

        $this->decorate($event->getResponse(), $origin);
    }

    private function decorate(Response $response, string $origin): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', self::METHODS);
        $response->headers->set('Access-Control-Allow-Headers', self::HEADERS);
        $response->headers->set('Access-Control-Expose-Headers', self::EXPOSED);
        $response->headers->set('Access-Control-Max-Age', '3600');

        // Sin credenciales: la API se autentica con cabecera, no con cookies.
        $response->headers->set('Vary', 'Origin', false);
    }

    /**
     * El origen de la petición, si está permitido.
     */
    private function origin(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');

        if (null === $origin || '' === trim($origin)) {
            return null;
        }

        $origin = trim($origin);

        if (!in_array($origin, $this->allowedOrigins, true)) {
            return null;
        }

        return $origin;
    }

    private function isApiRequest(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/v1/');
    }
}
