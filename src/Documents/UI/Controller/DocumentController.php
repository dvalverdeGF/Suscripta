<?php

declare(strict_types=1);

namespace App\Documents\UI\Controller;

use App\Documents\Application\AttachDocumentToService;
use App\Documents\Application\DeleteDocument;
use App\Documents\Application\DocumentContents;
use App\Documents\Application\DownloadDocument;
use App\Documents\Application\Dto\DocumentUpload;
use App\Documents\Application\UploadDocument;
use App\Documents\Domain\Entity\Document;
use App\Documents\Domain\Enum\DocumentType;
use App\Documents\Domain\Exception\DocumentStorageException;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Documents\UI\Form\DocumentFormData;
use App\Documents\UI\Form\DocumentFormType;
use App\Identity\Domain\Entity\User;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function is_string;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Documentos: subida, consulta, descarga y borrado (ARCHITECTURE.md §4.4).
 *
 * El binario nunca se sirve por URL directa. La descarga pasa por aquí para que
 * el filtro de tenencia decida si el documento es visible y para que la lectura
 * quede auditada (SECURITY.md §1).
 */
#[Route('/documents', name: 'app_documents_')]
#[IsGranted('ROLE_USER')]
final class DocumentController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documents,
        private readonly ServiceRepositoryInterface $services,
        private readonly UploadDocument $uploadDocument,
        private readonly DownloadDocument $downloadDocument,
        private readonly DeleteDocument $deleteDocument,
        private readonly AttachDocumentToService $attachDocument,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $type = DocumentType::tryFrom((string) $request->query->get('type', ''));
        $documents = $this->documents->findForOrganization($type);

        $services = [];
        foreach ($documents as $document) {
            $serviceId = $document->getServiceId();

            if (null !== $serviceId) {
                $services[$serviceId->toRfc4122()] = $this->services->find($serviceId);
            }
        }

        return $this->render('documents/index.html.twig', [
            'documents' => $documents,
            'services' => $services,
            'type' => $type,
            'types' => DocumentType::cases(),
            'counts' => $this->documents->countByType(),
            'total' => $this->documents->countForOrganization(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new DocumentFormData();
        $form = $this->createForm(DocumentFormType::class, $data, [
            'service_choices' => $this->serviceChoices(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $data->file;

            if (null === $file) {
                $form->addError(new FormError('Selecciona un fichero.'));

                return $this->renderInvalid('documents/new.html.twig', $form);
            }

            try {
                $result = ($this->uploadDocument)(
                    new DocumentUpload(
                        contents: (string) $file->getContent(),
                        originalFilename: $file->getClientOriginalName(),
                        // El tipo que declara el navegador no es de fiar: se
                        // prefiere el que se deduce del contenido real.
                        mimeType: $file->getMimeType() ?? $file->getClientMimeType(),
                        type: $data->type ?? DocumentType::OTHER,
                        serviceId: $data->serviceId,
                    ),
                    $this->actorId(),
                );
            } catch (InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));

                return $this->renderInvalid('documents/new.html.twig', $form);
            }

            $document = $result['document'];

            $this->addFlash(
                'success',
                $result['created']
                    ? sprintf('«%s» se ha guardado.', $document->displayName())
                    : sprintf('«%s» ya estaba en tus documentos. No lo hemos duplicado.', $document->displayName()),
            );

            return $this->redirectToRoute('app_documents_show', ['id' => $document->getId()->toRfc4122()]);
        }

        return $this->render('documents/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(Document $document): Response
    {
        $serviceId = $document->getServiceId();

        return $this->render('documents/show.html.twig', [
            'document' => $document,
            'service' => null === $serviceId ? null : $this->services->find($serviceId),
            'serviceChoices' => $this->serviceChoices(),
        ]);
    }

    #[Route('/{id}/download', name: 'download', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function download(Document $document): Response
    {
        try {
            $contents = ($this->downloadDocument)($document->getId(), $this->actorId());
        } catch (InvalidArgumentException|DocumentStorageException $exception) {
            throw $this->createNotFoundException($exception->getMessage());
        }

        return $this->fileResponse($contents);
    }

    #[Route('/{id}/attach', name: 'attach', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function attach(Document $document, Request $request): Response
    {
        $this->assertCsrf($request, 'document_attach_'.$document->getId()->toRfc4122());

        $serviceId = $this->uuidOrNull($request->request->get('serviceId'));

        try {
            ($this->attachDocument)($document->getId(), $serviceId, $this->actorId());
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_documents_show', ['id' => $document->getId()->toRfc4122()]);
        }

        $this->addFlash(
            'success',
            null === $serviceId
                ? 'El documento ya no está asignado a ningún servicio.'
                : 'Documento asignado al servicio.',
        );

        return $this->redirectToRoute('app_documents_show', ['id' => $document->getId()->toRfc4122()]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(Document $document, Request $request): Response
    {
        $this->assertCsrf($request, 'document_delete_'.$document->getId()->toRfc4122());

        ($this->deleteDocument)($document->getId(), $this->actorId());

        $this->addFlash('success', sprintf('«%s» se ha eliminado.', $document->displayName()));

        return $this->redirectToRoute('app_documents_index');
    }

    /**
     * Un formulario con un error de negocio se responde con 422, no con 200:
     * el envío no se ha aceptado y el cliente debe poder distinguirlo.
     *
     * @param FormInterface<mixed> $form
     */
    private function renderInvalid(string $view, FormInterface $form): Response
    {
        $response = $this->render($view, ['form' => $form]);
        $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);

        return $response;
    }

    private function fileResponse(DocumentContents $contents): Response
    {
        $document = $contents->document;

        $response = new Response($contents->contents);
        $response->headers->set('Content-Type', $document->getMimeType());
        $response->headers->set('Content-Length', (string) $document->getSizeBytes());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $document->getOriginalFilename(),
                // El nombre puede traer acentos o caracteres no ASCII; el
                // fallback tiene que ser ASCII puro o la cabecera es inválida.
                $this->asciiFallback($document->getOriginalFilename()),
            ),
        );

        return $response;
    }

    private function asciiFallback(string $filename): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);

        if (false === $ascii || '' === $ascii) {
            return 'documento';
        }

        return $ascii;
    }

    /**
     * @return array<string, Uuid>
     */
    private function serviceChoices(): array
    {
        $choices = [];

        foreach ($this->services->findForOrganization() as $service) {
            $choices[$service->getName()] = $service->getId();
        }

        return $choices;
    }

    private function actorId(): ?Uuid
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }
    }

    private function uuidOrNull(mixed $value): ?Uuid
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return Uuid::fromString($value);
        } catch (Throwable) {
            return null;
        }
    }
}
