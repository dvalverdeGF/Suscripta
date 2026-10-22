<?php

declare(strict_types=1);

namespace App\Api\UI\Controller\V1;

use App\Api\Application\ApiValueParser;
use App\Api\Application\CreateApiService;
use App\Api\Application\Dto\CreateServiceRequest;
use App\Api\Application\ReadModel\GetService;
use App\Api\Application\ReadModel\ListServices;
use App\Api\Domain\Exception\ResourceNotFoundException;
use App\Api\UI\Dto\ServicesQuery;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Servicios de la organización: el inventario de suscripciones.
 *
 * Es el endpoint de lectura más usado: un integrador lo llama para saber
 * qué está pagando la organización y con qué periodicidad.
 */
#[Route('/api/v1/services', name: 'api_v1_services_')]
#[OA\Tag(name: 'Servicios', description: 'Inventario de suscripciones de la organización.')]
final class ServiceController extends AbstractApiController
{
    public function __construct(
        private readonly ListServices $listServices,
        private readonly GetService $getService,
        private readonly CreateApiService $createService,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('SCOPE_READ')]
    #[OA\Get(
        path: '/api/v1/services',
        summary: 'Lista los servicios de la organización.',
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', description: 'Estado del servicio (active, cancelled, paused…).', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category_id', in: 'query', description: 'Filtra por categoría.', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'provider_id', in: 'query', description: 'Filtra por proveedor.', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'search', in: 'query', description: 'Texto libre.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', description: 'Página (desde 1).', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Elementos por página (máx. 100).', schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'La lista de servicios.', content: new OA\JsonContent(type: 'object')),
        ],
    )]
    public function index(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ServicesQuery $query = new ServicesQuery(),
    ): JsonResponse {
        return $this->collection(($this->listServices)(
            status: ApiValueParser::serviceStatus($query->status),
            categoryId: ApiValueParser::uuid($query->categoryId, 'category_id'),
            providerId: ApiValueParser::uuid($query->providerId, 'provider_id'),
            search: $query->search,
            sort: $query->sort,
            page: $query->page,
            perPage: $query->perPage,
        ));
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[IsGranted('SCOPE_READ')]
    #[OA\Get(
        path: '/api/v1/services/{id}',
        summary: 'Devuelve un servicio concreto.',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'El servicio.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 404, description: 'El servicio no existe o no es de esta organización.'),
        ],
    )]
    public function show(Uuid $id): JsonResponse
    {
        $service = ($this->getService)($id);

        if (null === $service) {
            throw ResourceNotFoundException::of('el servicio');
        }

        return $this->resource($service);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('SCOPE_WRITE')]
    #[OA\Post(
        path: '/api/v1/services',
        summary: 'Da de alta un servicio.',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object', required: ['name'])),
        responses: [
            new OA\Response(response: 201, description: 'El servicio creado.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 402, description: 'El plan no permite dar de alta más servicios.'),
            new OA\Response(response: 422, description: 'Los datos no son válidos.'),
        ],
    )]
    public function create(
        #[MapRequestPayload(mapWhenEmpty: true)]
        CreateServiceRequest $request,
    ): JsonResponse {
        $service = ($this->createService)($request);

        return $this->resource($service, Response::HTTP_CREATED);
    }
}
