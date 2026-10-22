<?php

declare(strict_types=1);

namespace App\Api\UI\EventListener;

use App\Api\Infrastructure\Security\ApiClient;
use App\Api\UI\Http\Problem;
use App\Shared\Application\RateLimit\RateLimitGuard;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Bundle\SecurityBundle\Security;

use function is_array;
use function max;
use function sprintf;
use function str_starts_with;
use function time;

/**
 * Límite de frecuencia de la API, **por clave** (Fase 18).
 *
 * ## Por qué por clave y no por IP
 *
 * La API la usan integradores: un ERP, un panel de control, un script. Varias
 * keys pueden salir del mismo servidor (NAT, proxy, CI), así que el límite
 * por IP castigaría a un cliente por el comportamiento de otro. La **clave de
 * API** es la unidad real de consumo y, además, es la que se puede explicar:
 * «su clave `sk_a1B2…` ha superado el límite de 300 peticiones por minuto».
 *
 * ## Por qué no cuenta las peticiones no autenticadas
 *
 * Una petición sin clave válida no llega a este punto: el firewall la
 * rechaza antes. Contarlas aquí no aportaría nada (el atacante no gasta cuota
 * de nadie) y haría que un error de configuración pareciera un límite
 * alcanzado.
 *
 * ## Las cabeceras de la respuesta
 *
 * `X-RateLimit-Limit`, `X-RateLimit-Remaining` y `X-RateLimit-Reset` allows
 * al integrador **adaptarse**: un sincronizador que ve que le quedan pocas
 * peticiones puede esperar a que se reponen, en vez de recibir un 429 y
 * reintentar a lo loco.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: -8)]
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -8)]
final readonly class ApiRateLimitListener
{
    private const LIMIT_ATTRIBUTE = '_api_rate_limit';

    public function __construct(
        private Security $security,
        private RateLimitGuard $rateLimits,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/v1/')) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof ApiClient) {
            return;
        }

        $limit = $this->rateLimits->consume('api', $user->getApiKeyId()->toRfc4122());
        $consumed = $limit;

        $request->attributes->set(self::LIMIT_ATTRIBUTE, [
            'limit' => $consumed->getLimit(),
            'remaining' => $consumed->getRemainingTokens(),
            'reset' => $consumed->getRetryAfter()->getTimestamp(),
        ]);

        if ($consumed->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $consumed->getRetryAfter()->getTimestamp() - time());

        $response = Problem::tooManyRequests(
            sprintf('Se ha superado el límite de %d peticiones por minuto.', $consumed->getLimit()),
            $retryAfter,
        )->toResponse($request->getPathInfo());

        $response->headers->set('Retry-After', (string) $retryAfter);

        $event->setResponse($response);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $limit = $event->getRequest()->attributes->get(self::LIMIT_ATTRIBUTE);

        if (!is_array($limit)) {
            return;
        }

        $response = $event->getResponse();

        $response->headers->set('X-RateLimit-Limit', (string) $limit['limit']);
        $response->headers->set('X-RateLimit-Remaining', (string) $limit['remaining']);
        $response->headers->set('X-RateLimit-Reset', (string) $limit['reset']);
    }
}
