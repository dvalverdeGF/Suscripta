<?php

declare(strict_types=1);

namespace App\Api\UI\Controller\V1;

use App\Api\Application\ApiValueParser;
use App\Api\Application\ConfirmApiProposal;
use App\Api\Application\Dto\ConfirmProposalRequest;
use App\Api\Application\ReadModel\ListProposals;
use App\Api\UI\Dto\ProposalsQuery;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Propuestas de descubrimiento: las suscripciones que el pipeline ha detectado
 * y que todavía están pendientes de decisión.
 */
#[Route('/api/v1/proposals', name: 'api_v1_proposals_')]
#[OA\Tag(name: 'Propuestas', description: 'Suscripciones detectadas por el pipeline, pendientes de decisión.')]
final class ProposalController extends AbstractApiController
{
    public function __construct(
        private readonly ListProposals $listProposals,
        private readonly ConfirmApiProposal $confirmProposal,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('SCOPE_READ')]
    #[OA\Get(
        path: '/api/v1/proposals',
        summary: 'Lista las propuestas de descubrimiento.',
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', description: 'Estado (pending, confirmed, edited, ignored, expired).', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', description: 'Tipo de descubrimiento.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', description: 'Página (desde 1).', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Elementos por página (máx. 100).', schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'La lista de propuestas.', content: new OA\JsonContent(type: 'object')),
        ],
    )]
    public function index(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ProposalsQuery $query = new ProposalsQuery(),
    ): JsonResponse {
        return $this->collection(($this->listProposals)(
            status: ApiValueParser::discoveryStatus($query->status),
            type: ApiValueParser::discoveryType($query->type),
            page: $query->page,
            perPage: $query->perPage,
        ));
    }

    #[Route('/{id}/confirm', name: 'confirm', methods: ['POST'])]
    #[IsGranted('SCOPE_WRITE')]
    #[OA\Post(
        path: '/api/v1/proposals/{id}/confirm',
        summary: 'Confirma una propuesta, opcionalmente corrigiendo sus datos.',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'La propuesta confirmada.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 404, description: 'La propuesta no existe o no es de esta organización.'),
            new OA\Response(response: 422, description: 'Los datos no son válidos.'),
        ],
    )]
    public function confirm(
        Uuid $id,
        #[MapRequestPayload(mapWhenEmpty: true)]
        ConfirmProposalRequest $request,
    ): JsonResponse {
        return $this->resource(($this->confirmProposal)($id, $request));
    }
}
