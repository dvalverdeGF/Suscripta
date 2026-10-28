<?php

declare(strict_types=1);

namespace App\Api\UI\Http;

use function sprintf;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Errores de la API en formato `application/problem+json` (RFC 9457).
 *
 * ## Por qué este formato y no un `{"error": "..."}` cualquiera
 *
 * Es el estándar de la IETF para errores HTTP: dice qué pasó (`type`), con qué
 * título (`title`), en qué código (`status`) y con qué detalle (`detail`). Un
 * cliente puede entender el error **sin conocer esta API**, porque el
 * formato ya está definido.
 *
 * ## `type` es una URI
 *
 * Apunta a la documentación del error (`/docs/api/errors#…`). Así, un
 * integrador que recibe un `403` puede seguir el enlace y saber qué hacer.
 *
 * ## Los errores de validación llevan el detalle campo a campo
 *
 * `violations` es una extensión de la RFC 9457 (la definen algunos
 * documentos de OpenAPI): una lista de `{field, message}`. Un `422` sin
 * detalle es un `422` inútil: el integrador no sabe qué campo corregir.
 */
final class Problem
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        private readonly int $status,
        private readonly string $title,
        private readonly string $detail,
        private readonly string $type = 'about:blank',
        private readonly array $extra = [],
    ) {
    }

    public static function badRequest(string $detail): self
    {
        return new self(400, 'Petición incorrecta', $detail, '/docs/api/errors#bad-request');
    }

    public static function unauthorized(string $detail = 'Falta la credencial o no es válida.'): self
    {
        return new self(401, 'No autenticado', $detail, '/docs/api/errors#unauthorized');
    }

    public static function forbidden(string $detail = 'La clave no tiene permiso para esta operación.'): self
    {
        return new self(403, 'Sin permiso', $detail, '/docs/api/errors#forbidden');
    }

    public static function notFound(string $detail = 'El recurso no existe.'): self
    {
        return new self(404, 'No encontrado', $detail, '/docs/api/errors#not-found');
    }

    public static function methodNotAllowed(string $detail = 'Ese método no está permitido en esta ruta.'): self
    {
        return new self(405, 'Método no permitido', $detail, '/docs/api/errors#method-not-allowed');
    }

    public static function conflict(string $detail): self
    {
        return new self(409, 'Conflicto', $detail, '/docs/api/errors#conflict');
    }

    /**
     * El plan del cliente no da para lo que se pide.
     *
     * `402 Payment Required` es el código que reservó la IETF exactamente para
     * esto: «la petición es válida, pero no se puede pagar». Como aquí el
     * «pago» es el plan, el código encaja mejor que un 403.
     *
     * @param array<string, mixed> $extra
     */
    public static function paymentRequired(string $detail, array $extra = []): self
    {
        return new self(402, 'Límite del plan alcanzado', $detail, '/docs/api/errors#payment-required', $extra);
    }

    /**
     * @param list<array{field: string, message: string}> $violations
     */
    public static function unprocessable(string $detail, array $violations = []): self
    {
        return new self(
            422,
            'Entidad no procesable',
            $detail,
            '/docs/api/errors#unprocessable',
            [] === $violations ? [] : ['violations' => $violations],
        );
    }

    public static function tooManyRequests(string $detail, int $retryAfter): self
    {
        return new self(429, 'Demasiadas peticiones', $detail, '/docs/api/errors#too-many-requests', [
            'retry_after' => $retryAfter,
        ]);
    }

    public static function internalError(string $detail = 'Se ha producido un error inesperado.'): self
    {
        return new self(500, 'Error interno', $detail, '/docs/api/errors#internal-error');
    }

    public function toResponse(?string $instance = null): JsonResponse
    {
        $payload = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
        ];

        if (null !== $instance) {
            $payload['instance'] = $instance;
        }

        foreach ($this->extra as $key => $value) {
            $payload[$key] = $value;
        }

        $response = new JsonResponse($payload, $this->status);
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function make(int $status, string $title, string $detail, array $extra = []): self
    {
        return new self($status, $title, $detail, sprintf('/docs/api/errors#%d', $status), $extra);
    }
}
