<?php

declare(strict_types=1);

namespace App\Subscriptions\UI\Controller;

use App\Identity\Domain\Entity\User;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Subscriptions\Application\CancelSubscription;
use App\Subscriptions\Application\ChangeSubscriptionStatus;
use App\Subscriptions\Application\CreateSubscription;
use App\Subscriptions\Application\RenewSubscription;
use App\Subscriptions\Application\UpdateSubscription;
use App\Subscriptions\Domain\Entity\Subscription;
use App\Subscriptions\Domain\Enum\SubscriptionStatus;
use App\Subscriptions\Domain\Repository\PlanRepositoryInterface;
use App\Subscriptions\Domain\Repository\SubscriptionFilters;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use App\Subscriptions\UI\Form\SubscriptionFormData;
use App\Subscriptions\UI\Form\SubscriptionFormType;
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
 * Panel de suscripciones: alta, edición y ciclo de vida.
 *
 * No es el inventario de servicios. Un servicio es lo que se paga; una
 * suscripción es el contrato que lo sostiene, con su plan, su periodo y su
 * estado. Se pueden enlazar (opcionalmente) cuando ambos hablan de lo mismo.
 */
#[Route('/subscriptions', name: 'app_subscriptions_')]
#[IsGranted('ROLE_USER')]
final class SubscriptionController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly ServiceRepositoryInterface $services,
        private readonly PlanRepositoryInterface $plans,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = new SubscriptionFilters(
            status: SubscriptionStatus::tryFrom($request->query->getString('status')),
            planId: $this->uuidOrNull($request->query->get('plan')),
            search: '' === $request->query->getString('q') ? null : $request->query->getString('q'),
            sort: $request->query->getString('sort', 'renewal'),
        );

        $subscriptions = $this->subscriptions->findForOrganization($filters);

        return $this->render('subscriptions/index.html.twig', [
            'subscriptions' => $subscriptions,
            'filters' => $filters,
            'statuses' => SubscriptionStatus::cases(),
            'counts' => $this->subscriptions->countByStatus(),
            'serviceNames' => $this->serviceNamesById(),
            'planNames' => $this->planNamesById(),
            'plans' => $this->plans->findAll(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, CreateSubscription $createSubscription): Response
    {
        $data = new SubscriptionFormData();
        $form = $this->createForm(SubscriptionFormType::class, $data, $this->formOptions());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $subscription = $createSubscription($data->toInput(), $this->actorId());

            $this->addFlash('success', sprintf('«%s» se ha añadido a tus suscripciones.', $subscription->getName()));

            return $this->redirectToRoute('app_subscriptions_show', ['id' => $subscription->getId()->toRfc4122()]);
        }

        return $this->render('subscriptions/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(Subscription $subscription): Response
    {
        $service = null === $subscription->getServiceId()
            ? null
            : $this->services->find($subscription->getServiceId());

        return $this->render('subscriptions/show.html.twig', [
            'subscription' => $subscription,
            'service' => $service,
            'plan' => null === $subscription->getPlanId() ? null : $this->plans->find($subscription->getPlanId()),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function edit(Subscription $subscription, Request $request, UpdateSubscription $updateSubscription): Response
    {
        $data = SubscriptionFormData::fromSubscription($subscription);
        $form = $this->createForm(SubscriptionFormType::class, $data, $this->formOptions());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $updateSubscription($subscription, $data->toInput());

            $this->addFlash('success', 'Los datos de la suscripción se han guardado.');

            return $this->redirectToRoute('app_subscriptions_show', ['id' => $subscription->getId()->toRfc4122()]);
        }

        return $this->render('subscriptions/edit.html.twig', [
            'form' => $form,
            'subscription' => $subscription,
        ]);
    }

    #[Route('/{id}/status/{status}', name: 'status', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function changeStatus(Subscription $subscription, string $status, Request $request, ChangeSubscriptionStatus $changeStatus): Response
    {
        $this->assertCsrf($request, $this->csrfId($subscription));

        $target = SubscriptionStatus::tryFrom($status);

        if (null === $target) {
            $this->addFlash('error', 'Ese cambio de estado no existe.');

            return $this->redirectToRoute('app_subscriptions_show', ['id' => $subscription->getId()->toRfc4122()]);
        }

        try {
            $changeStatus($subscription, $target);
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_subscriptions_show', ['id' => $subscription->getId()->toRfc4122()]);
        }

        $this->addFlash('success', sprintf('La suscripción queda en estado «%s».', $target->label()));

        return $this->redirectToRoute('app_subscriptions_show', ['id' => $subscription->getId()->toRfc4122()]);
    }

    #[Route('/{id}/renew', name: 'renew', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function renew(Subscription $subscription, Request $request, RenewSubscription $renewSubscription): Response
    {
        $this->assertCsrf($request, $this->csrfId($subscription));

        $renewSubscription($subscription);

        $this->addFlash('success', sprintf(
            'Renovada hasta el %s.',
            $subscription->getCurrentPeriodEnd()->format('d/m/Y'),
        ));

        return $this->redirectToRoute('app_subscriptions_show', ['id' => $subscription->getId()->toRfc4122()]);
    }

    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function cancel(Subscription $subscription, Request $request, CancelSubscription $cancelSubscription): Response
    {
        $this->assertCsrf($request, $this->csrfId($subscription));

        $cancelSubscription($subscription);

        $endsAt = $subscription->getEndsAt();
        $this->addFlash('success', null === $endsAt
            ? 'La suscripción queda cancelada.'
            : sprintf('La suscripción no se renovará. Sigue activa hasta el %s.', $endsAt->format('d/m/Y')));

        return $this->redirectToRoute('app_subscriptions_show', ['id' => $subscription->getId()->toRfc4122()]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(Subscription $subscription, Request $request): Response
    {
        $this->assertCsrf($request, 'subscription_delete_'.$subscription->getId()->toRfc4122());

        $name = $subscription->getName();
        $this->subscriptions->remove($subscription);

        $this->addFlash('success', sprintf('«%s» se ha eliminado de tus suscripciones.', $name));

        return $this->redirectToRoute('app_subscriptions_index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'service_choices' => $this->serviceNames(),
            'plan_choices' => $this->planChoices(),
        ];
    }

    /**
     * Planes del catálogo para el desplegable de la suscripción.
     *
     * Se incluyen también los retirados —marcados como tales— porque una
     * suscripción viva puede apuntar a un plan que ya no se ofrece, y si no
     * apareciera, guardar cualquier otro cambio la dejaría sin plan.
     *
     * @return array<string, Uuid>
     */
    private function planChoices(): array
    {
        $choices = [];

        foreach ($this->plans->findAll() as $plan) {
            $label = $plan->getName();

            if (!$plan->isActive()) {
                $label = sprintf('%s (retirado)', $label);
            }

            $choices[$label] = $plan->getId();
        }

        return $choices;
    }

    /**
     * @return array<string, string> id (RFC 4122) => nombre, para el listado
     */
    private function planNamesById(): array
    {
        $names = [];

        foreach ($this->plans->findAll() as $plan) {
            $names[$plan->getId()->toRfc4122()] = $plan->getName();
        }

        return $names;
    }

    /**
     * @return array<string, Uuid> nombre => id, para el desplegable de enlace
     */
    private function serviceNames(): array
    {
        $choices = [];

        foreach ($this->services->findForOrganization() as $service) {
            $choices[$service->getName()] = $service->getId();
        }

        return $choices;
    }

    /**
     * @return array<string, string> id (RFC 4122) => nombre, para el listado
     */
    private function serviceNamesById(): array
    {
        $names = [];

        foreach ($this->services->findForOrganization() as $service) {
            $names[$service->getId()->toRfc4122()] = $service->getName();
        }

        return $names;
    }

    private function csrfId(Subscription $subscription): string
    {
        return 'subscription_lifecycle_'.$subscription->getId()->toRfc4122();
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }
    }

    private function actorId(): ?Uuid
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
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
