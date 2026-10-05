<?php

declare(strict_types=1);

namespace App\Discovery\UI\Controller;

use App\Catalog\Domain\Repository\CategoryRepositoryInterface;
use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Discovery\Application\ConfirmDiscovery;
use App\Discovery\Application\DismissDiscovery;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Discovery\UI\Form\DiscoveryFormData;
use App\Discovery\UI\Form\DiscoveryFormType;
use App\Identity\Domain\Entity\User;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\Currency;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Bandeja de revisión de propuestas (ARCHITECTURE.md §13.11).
 *
 * Es la pantalla que cierra el bucle del producto: el sistema ha leído el
 * correo, ha deducido algo y aquí el usuario decide. Todo lo que se muestra
 * viene acompañado de **por qué** se propone —puntuación, señales y el correo
 * original—, porque una propuesta sin justificación es indistinguible de un
 * error.
 */
#[Route('/discoveries', name: 'app_discoveries_')]
#[IsGranted('ROLE_USER')]
final class DiscoveryController extends AbstractController
{
    public function __construct(
        private readonly DiscoveryRepositoryInterface $discoveries,
        private readonly EmailMessageRepositoryInterface $messages,
        private readonly ServiceRepositoryInterface $services,
        private readonly ProviderRepositoryInterface $providers,
        private readonly CategoryRepositoryInterface $categories,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = DiscoveryStatus::tryFrom((string) $request->query->get('status', '')) ?? DiscoveryStatus::PENDING;
        $discoveries = $this->discoveries->findForOrganization($status);

        $sources = [];
        foreach ($discoveries as $discovery) {
            $messageId = $discovery->getSourceEmailMessageId();

            if (null !== $messageId) {
                $sources[$discovery->getId()->toRfc4122()] = $this->messages->find($messageId);
            }
        }

        return $this->render('discoveries/index.html.twig', [
            'discoveries' => $discoveries,
            'sources' => $sources,
            'status' => $status,
            'counts' => $this->discoveries->countByStatus(),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(Discovery $discovery): Response
    {
        $data = DiscoveryFormData::fromDiscovery($discovery);
        $form = $this->createForm(DiscoveryFormType::class, $data, $this->formOptions($data->currency ?? Currency::EUR));

        $messageId = $discovery->getSourceEmailMessageId();

        return $this->render('discoveries/show.html.twig', [
            'discovery' => $discovery,
            'form' => $form,
            'source' => null === $messageId ? null : $this->messages->find($messageId),
            'matchedService' => null === $discovery->getMatchedServiceId()
                ? null
                : $this->services->find($discovery->getMatchedServiceId()),
            'evidence' => $this->discoveries->findEvidence($discovery->getId()),
        ]);
    }

    #[Route('/{id}/confirm', name: 'confirm', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function confirm(Discovery $discovery, Request $request, ConfirmDiscovery $confirmDiscovery): Response
    {
        $data = DiscoveryFormData::fromDiscovery($discovery);
        $form = $this->createForm(DiscoveryFormType::class, $data, $this->formOptions($data->currency ?? Currency::EUR));
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $messageId = $discovery->getSourceEmailMessageId();

            return $this->render('discoveries/show.html.twig', [
                'discovery' => $discovery,
                'form' => $form,
                'source' => null === $messageId ? null : $this->messages->find($messageId),
                'matchedService' => null === $discovery->getMatchedServiceId()
                    ? null
                    : $this->services->find($discovery->getMatchedServiceId()),
                'evidence' => $this->discoveries->findEvidence($discovery->getId()),
            ]);
        }

        try {
            $service = $confirmDiscovery($discovery, $data->toInput(), $this->actorId());
        } catch (InvalidArgumentException $exception) {
            $form->addError(new FormError($exception->getMessage()));

            return $this->render('discoveries/show.html.twig', [
                'discovery' => $discovery,
                'form' => $form,
                'source' => null,
                'matchedService' => null,
                'evidence' => $this->discoveries->findEvidence($discovery->getId()),
            ]);
        }

        $this->addFlash('success', sprintf('«%s» ya forma parte de tus servicios.', $service->getName()));

        return $this->redirectToRoute('app_services_show', ['id' => $service->getId()->toRfc4122()]);
    }

    #[Route('/{id}/dismiss', name: 'dismiss', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function dismiss(Discovery $discovery, Request $request, DismissDiscovery $dismissDiscovery): Response
    {
        if (!$this->isCsrfTokenValid('discovery_dismiss_'.$discovery->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $reason = trim((string) $request->request->get('reason', ''));

        try {
            $dismissDiscovery($discovery, '' === $reason ? null : $reason, $this->actorId());
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_discoveries_show', ['id' => $discovery->getId()->toRfc4122()]);
        }

        $this->addFlash('info', 'Propuesta descartada. El sistema lo tendrá en cuenta para no volver a proponerla.');

        return $this->redirectToRoute('app_discoveries_index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Currency $currency): array
    {
        $organizationId = $this->tenantContext->requireOrganizationId();

        $providerChoices = [];
        foreach ($this->providers->findVisibleForOrganization($organizationId) as $provider) {
            $providerChoices[$provider->getName()] = $provider->getId();
        }

        $categoryChoices = [];
        foreach ($this->categories->findVisibleForOrganization($organizationId) as $category) {
            $categoryChoices[$category->getName()] = $category->getId();
        }

        return [
            'currency' => $currency,
            'provider_choices' => $providerChoices,
            'category_choices' => $categoryChoices,
        ];
    }

    private function actorId(): ?Uuid
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
    }
}
