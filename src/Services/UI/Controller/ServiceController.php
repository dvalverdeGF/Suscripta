<?php

declare(strict_types=1);

namespace App\Services\UI\Controller;

use App\Catalog\Domain\Repository\CategoryRepositoryInterface;
use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Services\Application\ChangeServicePrice;
use App\Services\Application\ChangeServiceStatus;
use App\Services\Application\CreateService;
use App\Services\Application\DeleteService;
use App\Services\Application\UpdateService;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Services\Domain\Service\ServiceCostCalculator;
use App\Services\UI\Form\ServiceFormData;
use App\Services\UI\Form\ServiceFormType;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use InvalidArgumentException;

use function is_string;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Inventario de servicios: alta, edición, historial de precios y ciclo de vida.
 *
 * El controlador no contiene reglas de negocio: traduce la petición a un caso de
 * uso y prepara la vista. Los cálculos de coste vienen de
 * `ServiceCostCalculator`, que es dominio puro y tiene tests unitarios.
 */
#[Route('/services', name: 'app_services_')]
#[IsGranted('ROLE_USER')]
final class ServiceController extends AbstractController
{
    public function __construct(
        private readonly ServiceRepositoryInterface $services,
        private readonly ProviderRepositoryInterface $providers,
        private readonly CategoryRepositoryInterface $categories,
        private readonly ServiceCostCalculator $costCalculator,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = new ServiceFilters(
            status: ServiceStatus::tryFrom((string) $request->query->get('status', '')),
            categoryId: $this->uuidOrNull($request->query->get('category')),
            providerId: $this->uuidOrNull($request->query->get('provider')),
            search: $request->query->get('q'),
            sort: $request->query->get('sort'),
        );

        $services = $this->services->findForOrganization($filters);
        $organizationId = $this->tenantContext->requireOrganizationId();

        $monthlyByService = [];
        foreach ($services as $service) {
            $monthly = $this->costCalculator->monthlyEquivalent($service);

            if (null !== $monthly) {
                $monthlyByService[$service->getId()->toRfc4122()] = $monthly;
            }
        }

        return $this->render('services/index.html.twig', [
            'services' => $services,
            'filters' => $filters,
            'providers' => $this->providers->findVisibleIndexedById($organizationId),
            'categories' => $this->categories->findVisibleIndexedById($organizationId),
            'monthlyByService' => $monthlyByService,
            'monthlyTotals' => $this->costCalculator->totalMonthlyByCurrency($services),
            'annualTotals' => $this->costCalculator->totalAnnualByCurrency($services),
            'statuses' => ServiceStatus::cases(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, CreateService $createService): Response
    {
        $data = new ServiceFormData();
        $form = $this->createForm(ServiceFormType::class, $data, $this->formOptions());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $service = $createService($data->toInput(), $this->actorId());

            $this->addFlash('success', sprintf('«%s» se ha añadido a tus servicios.', $service->getName()));

            return $this->redirectToRoute('app_services_show', ['id' => $service->getId()->toRfc4122()]);
        }

        return $this->render('services/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(Service $service): Response
    {
        $organizationId = $this->tenantContext->requireOrganizationId();

        return $this->render('services/show.html.twig', [
            'service' => $service,
            'provider' => null === $service->getProviderId() ? null : $this->providers->find($service->getProviderId()),
            'category' => null === $service->getCategoryId() ? null : $this->categories->find($service->getCategoryId()),
            'monthlyEquivalent' => $this->costCalculator->monthlyEquivalent($service),
            'annualCost' => $this->costCalculator->annualCost($service),
            'priceChangeRatio' => $this->costCalculator->priceChangeRatio($service),
            'organizationId' => $organizationId,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function edit(Service $service, Request $request, UpdateService $updateService): Response
    {
        $data = ServiceFormData::fromService($service);
        $form = $this->createForm(ServiceFormType::class, $data, $this->formOptions($service->getCurrency()));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $updateService($service, $data->toInput($service->getSource()), $this->actorId());

            $this->addFlash('success', 'Los datos del servicio se han guardado.');

            return $this->redirectToRoute('app_services_show', ['id' => $service->getId()->toRfc4122()]);
        }

        return $this->render('services/edit.html.twig', [
            'form' => $form,
            'service' => $service,
        ]);
    }

    #[Route('/{id}/price', name: 'price', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function changePrice(Service $service, Request $request, ChangeServicePrice $changeServicePrice): Response
    {
        if (!$this->isCsrfTokenValid('service_price_'.$service->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $rawAmount = trim((string) $request->request->get('amount', ''));
        $rawDate = trim((string) $request->request->get('validFrom', ''));

        if ('' === $rawAmount) {
            $this->addFlash('error', 'Indica el importe nuevo.');

            return $this->redirectToRoute('app_services_show', ['id' => $service->getId()->toRfc4122()]);
        }

        try {
            $amount = Money::fromDecimalString($rawAmount, $service->getCurrency());
            $validFrom = '' === $rawDate ? new DateTimeImmutable() : new DateTimeImmutable($rawDate);
        } catch (Throwable) {
            $this->addFlash('error', 'El importe o la fecha no tienen un formato válido.');

            return $this->redirectToRoute('app_services_show', ['id' => $service->getId()->toRfc4122()]);
        }

        try {
            $changed = $changeServicePrice($service, $amount, $validFrom, $this->nullableString($request->request->get('note')), $this->actorId());
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_services_show', ['id' => $service->getId()->toRfc4122()]);
        }

        $this->addFlash(
            $changed ? 'success' : 'info',
            $changed
                ? sprintf('El precio se ha actualizado a %s.', $amount->format())
                : 'El importe es el mismo que el vigente; no se ha añadido nada al historial.',
        );

        return $this->redirectToRoute('app_services_show', ['id' => $service->getId()->toRfc4122()]);
    }

    #[Route('/{id}/pause', name: 'pause', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function pause(Service $service, Request $request, ChangeServiceStatus $changeStatus): Response
    {
        $this->assertCsrf($request, 'service_lifecycle_'.$service->getId()->toRfc4122());

        $changeStatus->pause($service, $this->actorId());
        $this->addFlash('success', 'El servicio queda en pausa y deja de contar en tus costes.');

        return $this->redirectToRoute('app_services_show', ['id' => $service->getId()->toRfc4122()]);
    }

    #[Route('/{id}/resume', name: 'resume', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function resume(Service $service, Request $request, ChangeServiceStatus $changeStatus): Response
    {
        $this->assertCsrf($request, 'service_lifecycle_'.$service->getId()->toRfc4122());

        $changeStatus->resume($service, $this->actorId());
        $this->addFlash('success', 'El servicio vuelve a estar activo.');

        return $this->redirectToRoute('app_services_show', ['id' => $service->getId()->toRfc4122()]);
    }

    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function cancel(Service $service, Request $request, ChangeServiceStatus $changeStatus): Response
    {
        $this->assertCsrf($request, 'service_lifecycle_'.$service->getId()->toRfc4122());

        $changeStatus->cancel($service, $this->actorId());
        $this->addFlash('success', 'El servicio queda cancelado. Su historial se conserva.');

        return $this->redirectToRoute('app_services_show', ['id' => $service->getId()->toRfc4122()]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(Service $service, Request $request, DeleteService $deleteService): Response
    {
        $this->assertCsrf($request, 'service_delete_'.$service->getId()->toRfc4122());

        $name = $service->getName();
        $deleteService($service);

        $this->addFlash('success', sprintf('«%s» se ha eliminado junto con su historial.', $name));

        return $this->redirectToRoute('app_services_index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(?Currency $currency = null): array
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
            'currency' => $currency ?? Currency::EUR,
            'provider_choices' => $providerChoices,
            'category_choices' => $categoryChoices,
        ];
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

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
