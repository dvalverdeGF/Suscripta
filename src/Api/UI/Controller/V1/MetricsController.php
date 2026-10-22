<?php

declare(strict_types=1);

namespace App\Api\UI\Controller\V1;

use App\Api\Application\ReadModel\GetMetrics;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Métricas de la organización.
 *
 * Reutiliza el mismo resumen que construye el panel: la API y la web nunca
 * pueden discrepar sobre el mismo número.
 */
#[Route('/api/v1/metrics', name: 'api_v1_metrics_')]
#[OA\Tag(name: 'Métricas', description: 'Resumen económico y de actividad de la organización.')]
final class MetricsController extends AbstractApiController
{
    public function __construct(private readonly GetMetrics $getMetrics)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('SCOPE_READ')]
    #[OA\Get(
        path: '/api/v1/metrics',
        summary: 'Devuelve las métricas de la organización.',
        responses: [
            new OA\Response(response: 200, description: 'Las métricas.', content: new OA\JsonContent(type: 'object')),
        ],
    )]
    public function index(): JsonResponse
    {
        return $this->resource(($this->getMetrics)());
    }
}
