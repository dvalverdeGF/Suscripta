<?php

declare(strict_types=1);

namespace App\Api\UI\EventListener;

use App\Api\Domain\Exception\ResourceNotFoundException;
use App\Api\Infrastructure\Security\ApiClient;
use App\Api\UI\Http\Problem;
use App\Api\UI\Security\ApiAuthenticationEntryPoint;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\Exception\QuotaExceededException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

use function sprintf;

/**
 * Convierte cualquier excepción de la API en un error `problem+json`.
 *
 * ## Por qué un listener y no un `catch` en cada controlador
 *
 * Los errores de una API tienen que ser **uniformes**. Si cada controlador
 * capturara sus excepciones, cada uno lo haría a su manera y el integrador
 * tendría que aprender una forma de error por endpoint. Con un listener, hay
 * un solo sitio donde se decide qué significa cada excepción, y se añade un
 * endpoint nuevo sin tocar nada.
 *
 * ## Solo para la API
 *
 * El listener mira la ruta: si no es de la API, deja la excepción continuar
 * hacia los listeners del backoffice (que la convierten en un aviso o en una
 * página de error). Así, un error en el panel sigue siendo un error de panel.
 *
 * ## La prioridad
 *
 * Va **antes** que el listener de seguridad (que tiene prioridad 1) para
 * poder convertir el `AccessDeniedException` del firewall —el 403 de una
 * clave sin ámbito— en la misma forma que el 403 de un ámbito insuficiente
 * dentro de un controlador.
 *
 * ## Por qué el `401` no lo hace el firewall
 *
 * El firewall de seguridad también sabe responder `401`, pero lo hace con
 * su *entry point* y con su propia forma. Aquí se decide de forma explícita
 * para que el `401` de la API tenga exactamente el mismo cuerpo que el
 * `403` y el `422`: el mismo `problem+json`, con la cabecera
 * `WWW-Authenticate` que dice cómo autenticarse.
 *
 * Y hay un matiz importante: **no autenticado** y **autenticado sin
 * permiso** son dos cosas distintas, y el integrador tiene que poder
 * distinguirlas sin adivinar.
 *
 * - Sin credencial (o con una credencial inválida) → `401`: «no sé quién
 *   eres, identifícate». Es lo que dice la RFC 9110, y es lo que permite
 *   al cliente reintentar con otra clave.
 * - Con credencial válida pero sin el ámbito necesario → `403`: «sé quién
 *   eres, pero no puedes hacer esto». Reintentar con la misma clave no
 *   va a funcionar.
 *
 * Confundirlos es el error clásico de las APIs: un `403` donde debía ir un
 * `401` hace que el integrador termine reintentando eternamente con una
 * clave que sí funciona, solo que le falta permiso.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 64)]
final readonly class ApiExceptionListener
{
    public function __construct(
        private readonly Security $security,
        private readonly ApiAuthenticationEntryPoint $entryPoint,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/v1/')) {
            return;
        }

        $response = $this->response($event->getThrowable(), $request);

        if (null === $response) {
            return;
        }

        $event->setResponse($response);
    }

    /**
     * La respuesta correspondiente a la excepción.
     *
     * Devuelve `null` para las excepciones que no son de la API (por ejemplo,
     * un error de configuración), que deben seguir su camino para que se
     * vean en el log y en el panel de Symfony.
     */
    private function response(\Throwable $exception, Request $request): ?Response
    {
        if ($exception instanceof QuotaExceededException) {
            return $this->problem(
                Problem::paymentRequired(
                    sprintf(
                        'El plan %s permite %d %s y ya los has usado todos.',
                        $exception->planLabel,
                        $exception->limit ?? 0,
                        $exception->label,
                    ),
                    [
                        'capability' => $exception->capability,
                        'limit' => $exception->limit,
                        'usage' => $exception->usage,
                    ],
                ),
                $request,
            );
        }

        if ($exception instanceof ValidationFailedException) {
            return $this->problem(
                Problem::unprocessable(
                    'Los datos enviados no son válidos.',
                    $this->violations($exception),
                ),
                $request,
            );
        }

        if ($exception instanceof ResourceNotFoundException) {
            return $this->problem(Problem::notFound($exception->getMessage()), $request);
        }

        if ($exception instanceof AuthenticationException) {
            return $this->entryPoint->start($request, $exception);
        }

        if ($exception instanceof AccessDeniedException) {
            // Sin sesión plenamente autenticada no se puede ni hablar de
            // permisos: lo que falta es la identidad. Se responde `401` para
            // que el integrador sepa que tiene que mandar una clave (o
            // cambiarla), no que su clave no vale.
            if (!$this->isAuthenticated()) {
                return $this->entryPoint->start($request, new AuthenticationException('Falta la clave de API.'));
            }

            return $this->problem(Problem::forbidden(), $request);
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();

            $problem = match ($status) {
                Response::HTTP_NOT_FOUND => Problem::notFound($exception->getMessage() ?: 'El recurso no existe.'),
                Response::HTTP_METHOD_NOT_ALLOWED => Problem::methodNotAllowed(),
                Response::HTTP_BAD_REQUEST => Problem::badRequest($exception->getMessage()),
                default => Problem::make($status, Response::$statusTexts[$status] ?? 'Error', $exception->getMessage()),
            };

            return $this->problem($problem, $request);
        }

        if ($exception instanceof InvalidArgumentException) {
            // Los casos de uso validan la entrada con excepciones del dominio:
            // para la API son datos inválidos, no fallos del servidor.
            return $this->problem(Problem::unprocessable($exception->getMessage()), $request);
        }

        return null;
    }

    /**
     * Si la petición va con una identidad ya establecida.
     *
     * El firewall de la API es sin estado: no hay sesión de la que fiarse, así
     * que la única identidad posible es la que el propio autenticador acaba de
     * poner en el almacén de tokens a partir de la clave de API.
     *
     * ## Por qué no `$token->isAuthenticated()`
     *
     * Ese método se eliminó de `TokenInterface` en Symfony 8. La pregunta
     * «¿está autenticado?» la responde ahora el `AuthenticationTrustResolver`,
     * pero **no está registrado como servicio autowirable** (en Symfony 8 solo
     * existe el servicio privado `security.authentication.trust_resolver`), así
     * que no se puede inyectar por interfaz sin escribir un `alias` a mano.
     *
     * La alternativa es el helper `Security`, que es público y autowirable.
     * Y no basta con «hay un usuario»: lo que distingue un `401` de un `403` es
     * que la identidad sea **una clave de API**. Si arrive una persona del
     * panel, no es una llamada de integrador y hay que pedirle credenciales
     * (`401`), no negarle el permiso (`403`).
     */
    private function isAuthenticated(): bool
    {
        return $this->security->getUser() instanceof ApiClient;
    }

    /**
     * Serializa un `Problem` como respuesta.
     */
    private function problem(Problem $problem, Request $request): Response
    {
        return $problem->toResponse($request->getPathInfo());
    }

    /**
     * Errores de validación, campo a campo.
     *
     * @return list<array{field: string, message: string}>
     */
    private function violations(ValidationFailedException $exception): array
    {
        $violations = [];

        foreach ($exception->getViolations() as $violation) {
            $violations[] = [
                'field' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return $violations;
    }
}
