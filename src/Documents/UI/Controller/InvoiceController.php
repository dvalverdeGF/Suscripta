<?php

declare(strict_types=1);

namespace App\Documents\UI\Controller;

use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Documents\Application\CreateInvoice;
use App\Documents\Domain\Entity\Invoice;
use App\Documents\Domain\Enum\InvoiceStatus;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Documents\Domain\Repository\InvoiceRepositoryInterface;
use App\Documents\UI\Form\InvoiceFormData;
use App\Documents\UI\Form\InvoiceFormType;
use App\Identity\Domain\Entity\User;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Cobros documentados (ARCHITECTURE.md §4.4).
 *
 * Una factura es el hecho económico; el documento es el fichero. Se pueden
 * registrar por separado porque hay cobros que solo conocemos por el cuerpo de
 * un correo, sin adjunto (D-19).
 */
#[Route('/invoices', name: 'app_invoices_')]
#[IsGranted('ROLE_USER')]
final class InvoiceController extends AbstractController
{
    public function __construct(
        private readonly InvoiceRepositoryInterface $invoices,
        private readonly DocumentRepositoryInterface $documents,
        private readonly ServiceRepositoryInterface $services,
        private readonly ProviderRepositoryInterface $providers,
        private readonly CreateInvoice $createInvoice,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = InvoiceStatus::tryFrom((string) $request->query->get('status', ''));
        $invoices = $this->invoices->findForOrganization($status);

        $services = [];
        foreach ($invoices as $invoice) {
            $serviceId = $invoice->getServiceId();

            if (null !== $serviceId) {
                $services[$serviceId->toRfc4122()] = $this->services->find($serviceId);
            }
        }

        return $this->render('invoices/index.html.twig', [
            'invoices' => $invoices,
            'services' => $services,
            'status' => $status,
            'statuses' => InvoiceStatus::cases(),
            'total' => $this->invoices->countForOrganization(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new InvoiceFormData();
        $form = $this->createForm(InvoiceFormType::class, $data, $this->formOptions());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $result = ($this->createInvoice)($data->toInput(), $this->actorId());
            } catch (InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));

                return $this->renderInvalid('invoices/new.html.twig', $form);
            }

            $invoice = $result['invoice'];

            $this->addFlash(
                'success',
                $result['created']
                    ? sprintf('Factura de %s registrada.', $invoice->getTotal()->format())
                    : 'Esa factura ya estaba registrada. No la hemos duplicado.',
            );

            return $this->redirectToRoute('app_invoices_show', ['id' => $invoice->getId()->toRfc4122()]);
        }

        return $this->render('invoices/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(Invoice $invoice): Response
    {
        $serviceId = $invoice->getServiceId();
        $documentId = $invoice->getDocumentId();

        return $this->render('invoices/show.html.twig', [
            'invoice' => $invoice,
            'service' => null === $serviceId ? null : $this->services->find($serviceId),
            'document' => null === $documentId ? null : $this->documents->find($documentId),
        ]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderInvalid(string $view, FormInterface $form): Response
    {
        $response = $this->render($view, ['form' => $form]);
        $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        $organizationId = $this->tenantContext->requireOrganizationId();

        $serviceChoices = [];
        foreach ($this->services->findForOrganization() as $service) {
            $serviceChoices[$service->getName()] = $service->getId();
        }

        $providerChoices = [];
        foreach ($this->providers->findVisibleForOrganization($organizationId) as $provider) {
            $providerChoices[$provider->getName()] = $provider->getId();
        }

        return [
            'service_choices' => $serviceChoices,
            'provider_choices' => $providerChoices,
        ];
    }

    private function actorId(): ?Uuid
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
    }
}
