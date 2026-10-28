<?php

declare(strict_types=1);

namespace App\Subscriptions\UI\Controller;

use App\Identity\Domain\Enum\OrganizationPlan;
use App\Shared\Application\Entitlements\EntitlementSettings;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Subscriptions\Application\AddPlanFeature;
use App\Subscriptions\Application\ClosePlanPrice;
use App\Subscriptions\Application\CreatePlan;
use App\Subscriptions\Application\PublishPlanPrice;
use App\Subscriptions\Application\RemovePlanFeature;
use App\Subscriptions\Application\UpdatePlan;
use App\Subscriptions\Application\UpdatePlanFeature;
use App\Subscriptions\Domain\Entity\Plan;
use App\Subscriptions\Domain\Entity\PlanFeature;
use App\Subscriptions\Domain\Entity\PlanPrice;
use App\Subscriptions\Domain\Repository\PlanFeatureRepositoryInterface;
use App\Subscriptions\Domain\Repository\PlanPriceRepositoryInterface;
use App\Subscriptions\Domain\Repository\PlanRepositoryInterface;
use App\Subscriptions\UI\Form\PlanFeatureFormType;
use App\Subscriptions\UI\Form\PlanFormData;
use App\Subscriptions\UI\Form\PlanFormType;
use App\Subscriptions\UI\Form\PlanPriceFormData;
use App\Subscriptions\UI\Form\PlanPriceFormType;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_bool;
use function mb_strtolower;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Catálogo comercial: planes, precios y características (Fase 22).
 *
 * ## Por qué exige `ROLE_OPERATOR`
 *
 * Es la única pantalla del backoffice que **no** es de un inquilino. Un plan y
 * su precio son la oferta de Suscripta para todo el mundo: si cualquier usuario
 * pudiera editarlos, el primero que entrara cambiaría el precio de los demás.
 * El rol se concede a mano con `app:user:grant-operator` y no se hereda de
 * ninguna organización (D-80).
 *
 * ## Qué no se hace aquí
 *
 * Los **límites** del plan no se editan: se leen de `entitlements.yaml` y se
 * enseñan en modo lectura. Duplicarlos en la base de datos crearía una segunda
 * fuente de la verdad que se desincroniza el día que alguien toque el YAML.
 *
 * ## Qué no se borra
 *
 * Ni planes ni precios. Un plan se retira (desmarcar «Publicado») y un precio se
 * cierra: en los dos casos la fila queda, porque las suscripciones que los
 * referencian siguen existiendo y su historial tiene que poder leerse. Las
 * características sí se borran: son texto de presentación, no un dato con fecha.
 */
#[Route('/catalog', name: 'app_catalog_')]
#[IsGranted('ROLE_OPERATOR')]
final class CatalogController extends AbstractController
{
    public function __construct(
        private readonly PlanRepositoryInterface $plans,
        private readonly PlanPriceRepositoryInterface $prices,
        private readonly PlanFeatureRepositoryInterface $features,
        private readonly EntitlementSettings $entitlements,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $plans = $this->plans->findAll();
        $rows = [];

        foreach ($plans as $plan) {
            $rows[] = [
                'plan' => $plan,
                'prices' => $this->prices->findCurrentForPlan($plan->getId()),
                'features' => $this->features->findForPlan($plan->getId()),
            ];
        }

        return $this->render('catalog/index.html.twig', [
            'rows' => $rows,
            'missing_codes' => $this->missingCodes($plans),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, CreatePlan $createPlan): Response
    {
        $available = $this->missingCodes($this->plans->findAll());

        if ([] === $available) {
            $this->addFlash('info', 'Todos los planes de la aplicación tienen ya su ficha en el catálogo.');

            return $this->redirectToRoute('app_catalog_index');
        }

        $data = new PlanFormData();
        $data->code = $available[0];
        $data->position = $this->plans->nextPosition();

        $form = $this->createForm(PlanFormType::class, $data, ['available_codes' => $available]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $plan = $createPlan($data->toInput());
            } catch (InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->render('catalog/new.html.twig', ['form' => $form]);
            }

            $this->addFlash('success', sprintf('«%s» ya está en el catálogo.', $plan->getName()));

            return $this->redirectToRoute('app_catalog_show', ['id' => $plan->getId()->toRfc4122()]);
        }

        return $this->render('catalog/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(Plan $plan): Response
    {
        return $this->renderShow($plan);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function edit(Plan $plan, Request $request, UpdatePlan $updatePlan): Response
    {
        $data = PlanFormData::fromPlan($plan);

        // El código no cambia: cambiarlo convertiría el plan en otro y dejaría a
        // las suscripciones que lo referencian apuntando a un producto distinto.
        $form = $this->createForm(PlanFormType::class, $data, ['available_codes' => [$plan->getCode()]]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $updatePlan($plan, $data->toInput());

            $this->addFlash('success', 'El plan se ha guardado.');

            return $this->redirectToRoute('app_catalog_show', ['id' => $plan->getId()->toRfc4122()]);
        }

        return $this->render('catalog/edit.html.twig', [
            'form' => $form,
            'plan' => $plan,
        ]);
    }

    #[Route('/{id}/prices/new', name: 'price_new', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function newPrice(Plan $plan, Request $request, PublishPlanPrice $publishPlanPrice): Response
    {
        $data = new PlanPriceFormData();
        $form = $this->createForm(PlanPriceFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $price = $publishPlanPrice($plan, $data->toInput());
            } catch (InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->render('catalog/price_new.html.twig', ['form' => $form, 'plan' => $plan]);
            }

            $this->addFlash('success', sprintf(
                'Precio de %s guardado. El anterior, si lo había, queda en el historial.',
                $this->describePrice($price),
            ));

            return $this->redirectToRoute('app_catalog_show', ['id' => $plan->getId()->toRfc4122()]);
        }

        return $this->render('catalog/price_new.html.twig', [
            'form' => $form,
            'plan' => $plan,
        ]);
    }

    #[Route('/{id}/prices/{priceId}/close', name: 'price_close', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}', 'priceId' => '[0-9a-fA-F-]{36}'])]
    public function closePrice(Plan $plan, string $priceId, Request $request, ClosePlanPrice $closePlanPrice): Response
    {
        $price = $this->priceOf($plan, $priceId);
        $this->assertCsrf($request, 'catalog_price_'.$price->getId()->toRfc4122());

        $closePlanPrice($price);

        $this->addFlash('success', sprintf('%s deja de ofrecerse.', $this->describePrice($price)));

        return $this->redirectToRoute('app_catalog_show', ['id' => $plan->getId()->toRfc4122()]);
    }

    #[Route('/{id}/features', name: 'feature_add', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function addFeature(Plan $plan, Request $request, AddPlanFeature $addPlanFeature): Response
    {
        $form = $this->createForm(PlanFeatureFormType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderShow($plan, $form);
        }

        /** @var array{label: string, included: bool} $submitted */
        $submitted = $form->getData();

        try {
            $addPlanFeature($plan, $submitted['label'], $submitted['included']);
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->renderShow($plan);
        }

        $this->addFlash('success', 'Característica añadida.');

        return $this->redirectToRoute('app_catalog_show', ['id' => $plan->getId()->toRfc4122()]);
    }

    #[Route('/{id}/features/{featureId}/edit', name: 'feature_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}', 'featureId' => '[0-9a-fA-F-]{36}'])]
    public function editFeature(Plan $plan, string $featureId, Request $request, UpdatePlanFeature $updatePlanFeature): Response
    {
        $feature = $this->featureOf($plan, $featureId);

        $form = $this->createForm(PlanFeatureFormType::class, [
            'label' => $feature->getLabel(),
            'included' => $feature->isIncluded(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{label: string, included: bool} $submitted */
            $submitted = $form->getData();
            $updatePlanFeature($feature, $submitted['label'], $submitted['included']);

            $this->addFlash('success', 'Característica guardada.');

            return $this->redirectToRoute('app_catalog_show', ['id' => $plan->getId()->toRfc4122()]);
        }

        return $this->render('catalog/feature_edit.html.twig', [
            'form' => $form,
            'plan' => $plan,
            'feature' => $feature,
        ]);
    }

    #[Route('/{id}/features/{featureId}/delete', name: 'feature_delete', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}', 'featureId' => '[0-9a-fA-F-]{36}'])]
    public function deleteFeature(Plan $plan, string $featureId, Request $request, RemovePlanFeature $removePlanFeature): Response
    {
        $feature = $this->featureOf($plan, $featureId);
        $this->assertCsrf($request, 'catalog_feature_'.$feature->getId()->toRfc4122());

        $removePlanFeature($feature);

        $this->addFlash('success', 'Característica eliminada.');

        return $this->redirectToRoute('app_catalog_show', ['id' => $plan->getId()->toRfc4122()]);
    }

    /**
     * Ficha del plan, con el formulario de añadir característica ya construido.
     *
     * @param FormInterface<array{label: string, included: bool}|null>|null $addFeatureForm
     */
    private function renderShow(Plan $plan, ?FormInterface $addFeatureForm = null): Response
    {
        return $this->render('catalog/show.html.twig', [
            'plan' => $plan,
            'prices' => $this->prices->findCurrentForPlan($plan->getId()),
            'history' => $this->prices->findHistoryForPlan($plan->getId()),
            'features' => $this->features->findForPlan($plan->getId()),
            'limits' => $this->limits($plan),
            'add_feature_form' => ($addFeatureForm ?? $this->createForm(PlanFeatureFormType::class))->createView(),
        ]);
    }

    /**
     * Planes de la aplicación que todavía no tienen ficha en el catálogo.
     *
     * @param list<Plan> $plans
     *
     * @return list<OrganizationPlan>
     */
    private function missingCodes(array $plans): array
    {
        $taken = array_map(static fn (Plan $plan): string => $plan->getCode()->value, $plans);

        return array_values(array_filter(
            OrganizationPlan::cases(),
            static fn (OrganizationPlan $code): bool => !in_array($code->value, $taken, true),
        ));
    }

    /**
     * Límites del plan, leídos de la configuración y no del catálogo.
     *
     * @return list<array{label: string, value: string}>
     */
    private function limits(Plan $plan): array
    {
        $limits = [];

        foreach ($this->entitlements->capabilityNames() as $capability) {
            $limits[] = [
                'label' => $this->entitlements->labelFor($capability),
                'value' => $this->formatLimit($capability, $plan->getCode()),
            ];
        }

        return $limits;
    }

    private function formatLimit(string $capability, OrganizationPlan $code): string
    {
        $value = $this->entitlements->valueFor($code, $capability);

        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        if (null === $value) {
            return 'Sin límite';
        }

        $unit = $this->entitlements->unitFor($capability);

        return null === $unit ? (string) $value : sprintf('%d %s', $value, $unit);
    }

    private function describePrice(PlanPrice $price): string
    {
        return sprintf(
            '%s al %s',
            $price->getAmount()->format(),
            mb_strtolower($price->getBillingPeriod()->label()),
        );
    }

    private function priceOf(Plan $plan, string $priceId): PlanPrice
    {
        $price = $this->priceOrNull($priceId);

        if (null === $price || !$price->getPlan()->getId()->equals($plan->getId())) {
            throw $this->createNotFoundException('Ese precio no pertenece a este plan.');
        }

        return $price;
    }

    private function priceOrNull(string $priceId): ?PlanPrice
    {
        try {
            return $this->prices->find(Uuid::fromString($priceId));
        } catch (Throwable) {
            return null;
        }
    }

    private function featureOf(Plan $plan, string $featureId): PlanFeature
    {
        $feature = null;

        try {
            $feature = $this->features->find(Uuid::fromString($featureId));
        } catch (Throwable) {
            $feature = null;
        }

        if (null === $feature || !$feature->getPlan()->getId()->equals($plan->getId())) {
            throw $this->createNotFoundException('Esa característica no pertenece a este plan.');
        }

        return $feature;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }
    }
}
