<?php

declare(strict_types=1);

namespace App\Api\UI\Controller\V1;

use App\Api\Application\ReadModel\ListUpcomingCharges;
use App\Api\UI\Dto\ChargesQuery;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Próximos cobros de la organización.
 *
 * La ventana se cuenta **desde hoy**: «los cobros de los próximos 30 días»,
 * que es lo que pregunta un integrador para alimentar un calendario de pagos.
 */
#[Route('/api/v1/charges', name: 'api_v1_charges_')]
#[OA\Tag(name: 'Cobros', description: 'Próximos cobros de la organización.')]
final class ChargeController extends AbstractApiController
{
    public function __construct(private readonly ListUpcomingCharges $listCharges)
    {
    }

    #[Route('/upcoming', name: 'upcoming', methods: ['GET'])]
    #[IsGranted('SCOPE_READ')]
    #[OA\Get(
        path: '/api/v1/charges/upcoming',
        summary: 'Lista los cobros que vencen en los próximos días.',
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', description: 'Días a mirar (por defecto 30).', schema: new OA\Schema(type: 'integer', default: 30)),
            new OA\Parameter(name: 'page', in: 'query', description: 'Página (desde 1).', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Elementos por página (máx. 100).', schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'La lista de cobros.', content: new OA\JsonContent(type: 'object')),
        ],
    )]
    public function upcoming(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ChargesQuery $query = new ChargesQuery(),
    ): JsonResponse {
        return $this->collection(($this->listCharges)(
            days: $query->days,
            page: $query->page,
            perPage: $query->perPage,
        ));
    }
}
