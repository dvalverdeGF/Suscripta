<?php

declare(strict_types=1);

namespace App\Api\UI\Controller\V1;

use App\Api\Application\ReadModel\GetPlan;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Plan de la organización y consumo de cada límite.
 *
 * Un integrador necesita saber cuánto le queda antes de que la API le
 * devuelva un `402`: con estos datos puede avisar antes de quedarse a medias.
 */
#[Route('/api/v1/plan', name: 'api_v1_plan_')]
#[OA\Tag(name: 'Plan', description: 'Límites del plan y consumo actual.')]
final class PlanController extends AbstractApiController
{
    public function __construct(private readonly GetPlan $getPlan)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('SCOPE_READ')]
    #[OA\Get(
        path: '/api/v1/plan',
        summary: 'Devuelve el plan y el consumo de sus límites.',
        responses: [
            new OA\Response(response: 200, description: 'El plan y sus cuotas.', content: new OA\JsonContent(type: 'object')),
        ],
    )]
    public function index(): JsonResponse
    {
        return $this->resource(($this->getPlan)());
    }
}
