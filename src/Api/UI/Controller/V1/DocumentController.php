<?php

declare(strict_types=1);

namespace App\Api\UI\Controller\V1;

use App\Api\Application\ApiValueParser;
use App\Api\Application\CreateApiDocument;
use App\Api\Application\Dto\CreateDocumentRequest;
use App\Api\Application\ReadModel\ListDocuments;
use App\Api\UI\Dto\DocumentsQuery;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Documentos de la organización (facturas, recibos, contratos).
 *
 * ## Por qué el contenido va en base64
 *
 * La API es JSON de principio a fin. Así el integrador necesita un solo
 * cliente HTTP y no dos, y no tiene que negociar codificaciones que no son
 * JSON. El coste es un 33 % más de tamaño; la contrapartida es que toda la
 * API se consume con un `fetch`.
 */
#[Route('/api/v1/documents', name: 'api_v1_documents_')]
#[OA\Tag(name: 'Documentos', description: 'Facturas, recibos y contratos de la organización.')]
final class DocumentController extends AbstractApiController
{
    public function __construct(
        private readonly ListDocuments $listDocuments,
        private readonly CreateApiDocument $createDocument,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('SCOPE_READ')]
    #[OA\Get(
        path: '/api/v1/documents',
        summary: 'Lista los documentos de la organización.',
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', description: 'Tipo (invoice, receipt, contract, other).', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service_id', in: 'query', description: 'Filtra por servicio.', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'include_deleted', in: 'query', description: 'Incluye los borrados.', schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'page', in: 'query', description: 'Página (desde 1).', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Elementos por página (máx. 100).', schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'La lista de documentos.', content: new OA\JsonContent(type: 'object')),
        ],
    )]
    public function index(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        DocumentsQuery $query = new DocumentsQuery(),
    ): JsonResponse {
        return $this->collection(($this->listDocuments)(
            type: ApiValueParser::documentType($query->type),
            serviceId: ApiValueParser::uuid($query->serviceId, 'service_id'),
            includeDeleted: $query->includeDeleted,
            page: $query->page,
            perPage: $query->perPage,
        ));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('SCOPE_WRITE')]
    #[OA\Post(
        path: '/api/v1/documents',
        summary: 'Sube un documento.',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object', required: ['filename', 'content_base64'])),
        responses: [
            new OA\Response(response: 201, description: 'El documento subido.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 422, description: 'El contenido no es base64 válido o el tipo no está permitido.'),
        ],
    )]
    public function create(
        #[MapRequestPayload(mapWhenEmpty: true)]
        CreateDocumentRequest $request,
    ): JsonResponse {
        $document = ($this->createDocument)($request);

        return $this->resource($document, Response::HTTP_CREATED);
    }
}
