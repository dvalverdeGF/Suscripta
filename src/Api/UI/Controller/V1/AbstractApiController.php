<?php

declare(strict_types=1);

namespace App\Api\UI\Controller\V1;

use App\Api\Application\ApiCollection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base de los controladores de la API pública.
 *
 * ## Una sola forma de responder
 *
 * Toda respuesta lleva los recursos en `data`. Las listas añaden `meta` con
 * la paginación. Un integrador puede escribir un cliente que entienda **todas**
 * las respuestas de la API con dos reglas, en vez de uno por endpoint:
 *
 * ```php
 * $body = json_decode($response->getContent(), true);
 * foreach ($body['data'] as $item) { ... }
 * ```
 *
 * ## Por qué hereda de `AbstractController`
 *
 * Para poder usar `denyAccessUnlessGranted()` y `json()` sin reescribir
 * helpers que Symfony ya trae. Los controladores no usan ninguna de las dos
 * cosas: la autorización la resuelven los atributos `#[IsGranted]` y las
 * respuestas las construye esta clase. Se hereda por compatibilidad con el
 * resto del proyecto, no por necesidad.
 */
abstract class AbstractApiController extends AbstractController
{
    /**
     * Una lista de recursos con su paginación.
     */
    protected function collection(ApiCollection $collection): JsonResponse
    {
        return $this->json($collection->toArray());
    }

    /**
     * Un recurso suelto.
     *
     * @param array<string, mixed> $data
     */
    protected function resource(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return $this->json(['data' => $data], $status);
    }
}
