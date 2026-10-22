<?php

declare(strict_types=1);

namespace App\Api\UI\Controller\V1;

use App\Api\Application\ReadModel\ListMailAccounts;
use App\Api\Application\SyncApiMailbox;
use App\Api\UI\Dto\MailAccountsQuery;
use App\Api\UI\Dto\SyncMailboxQuery;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Buzones de la organización.
 *
 * ## Lo que la API no devuelve
 *
 * Ni la contraseña IMAP ni la credencial cifrada. El listado dice si el buzón
 * **tiene** credenciales, no cuáles son. Una API que devuelve secretos es una
 * API de la que no se puede hablar en un canal de soporte.
 */
#[Route('/api/v1/mail', name: 'api_v1_mail_')]
#[OA\Tag(name: 'Buzones', description: 'Buzones de correo conectados y sus sincronizaciones.')]
final class MailboxController extends AbstractApiController
{
    public function __construct(
        private readonly ListMailAccounts $listAccounts,
        private readonly SyncApiMailbox $syncMailbox,
    ) {
    }

    #[Route('/accounts', name: 'accounts', methods: ['GET'])]
    #[IsGranted('SCOPE_READ')]
    #[OA\Get(
        path: '/api/v1/mail/accounts',
        summary: 'Lista los buzones de la organización.',
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', description: 'Página (desde 1).', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Elementos por página (máx. 100).', schema: new OA\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'La lista de buzones.', content: new OA\JsonContent(type: 'object')),
        ],
    )]
    public function accounts(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        MailAccountsQuery $query = new MailAccountsQuery(),
    ): JsonResponse {
        return $this->collection(($this->listAccounts)(
            page: $query->page,
            perPage: $query->perPage,
        ));
    }

    #[Route('/accounts/{id}/sync', name: 'sync', methods: ['POST'])]
    #[IsGranted('SCOPE_WRITE')]
    #[OA\Post(
        path: '/api/v1/mail/accounts/{id}/sync',
        summary: 'Fuerza una sincronización del buzón.',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'La sincronización (con su estado).', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 404, description: 'El buzón no existe o no es de esta organización.'),
        ],
    )]
    public function sync(
        Uuid $id,
        #[MapRequestPayload(mapWhenEmpty: true)]
        SyncMailboxQuery $query = new SyncMailboxQuery(),
    ): JsonResponse {
        return $this->resource(($this->syncMailbox)($id, $query->months, $query->limit));
    }
}
