<?php

declare(strict_types=1);

namespace App\Api\UI\Controller\V1;

use App\Api\Application\ApiValueParser;
use App\Api\Application\ReadModel\ListAlerts;
use App\Api\UI\Dto\AlertsQuery;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Alertas de la organización.
 *
 * Es el endpoint que un integrador consulta para enterarse de los cambios:
 * cobros próximos, renovaciones, cambios de precio y avisos del pipeline.
 */
#[Route('/api/v1/alerts', name: 'api_v1_alerts_')]
#[OA\Tag(name: 'Alertas', description: 'Avisos de cobros, renovaciones y cambios.')]
final class AlertController extends AbstractApiController
{
    public function __construct(private readonly ListAlerts $listAlerts)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('SCOPE_READ')]
    #[OA\Get(
        path: '/api/v1/alerts',
        summary: 'Lista las alertas de la organización.',
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', description: 'Estado (open, acknowledged, dismissed, resolved).', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'severity', in: 'query', description: 'Severidad (info, warning, critical).', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', description: 'Página (desde 1).', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Elementos por página (máx. 100).', schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'La lista de alertas.', content: new OA\JsonContent(type: 'object')),
        ],
    )]
    public function index(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        AlertsQuery $query = new AlertsQuery(),
    ): JsonResponse {
        return $this->collection(($this->listAlerts)(
            status: ApiValueParser::alertStatus($query->status),
            severity: ApiValueParser::alertSeverity($query->severity),
            search: $query->search,
            sort: $query->sort,
            page: $query->page,
            perPage: $query->perPage,
        ));
    }
}
