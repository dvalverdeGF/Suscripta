<?php

declare(strict_types=1);

namespace App\Notifications\UI\Controller;

use App\Identity\Domain\Entity\User;
use App\Notifications\Application\Dto\NotificationPreferenceFormData;
use App\Notifications\Application\UpdateNotificationPreferences;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\AlertStatus;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Enum\NotificationChannel;
use App\Notifications\Domain\Repository\AlertRepositoryInterface;
use App\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Notifications\Domain\Service\AlertRules;
use App\Notifications\UI\Form\NotificationPreferenceFormType;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Clock;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Bandeja de avisos y preferencias (ARCHITECTURE.md §4.7).
 *
 * La bandeja es el canal principal del producto: el correo es opcional y lo
 * urgente es la única excepción. Cada aviso dice **por qué** existe —fecha,
 * importe, servicio—, porque un aviso que no se puede comprobar se acaba
 * ignorando.
 */
#[Route('/alerts', name: 'app_alerts_')]
#[IsGranted('ROLE_USER')]
final class AlertController extends AbstractController
{
    public function __construct(
        private readonly AlertRepositoryInterface $alerts,
        private readonly NotificationRepositoryInterface $notifications,
        private readonly NotificationPreferenceRepositoryInterface $preferences,
        private readonly ServiceRepositoryInterface $services,
        private readonly UpdateNotificationPreferences $updatePreferences,
        private readonly Clock $clock,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = AlertStatus::tryFrom((string) $request->query->get('status', '')) ?? AlertStatus::OPEN;
        $alerts = $this->alerts->findForOrganization($status);

        $services = [];
        foreach ($alerts as $alert) {
            $serviceId = $alert->getServiceId();

            if (null !== $serviceId) {
                $services[$serviceId->toRfc4122()] = $this->services->find($serviceId);
            }
        }

        return $this->render('alerts/index.html.twig', [
            'alerts' => $alerts,
            'services' => $services,
            'status' => $status,
            'counts' => $this->alerts->countByStatus(),
            'openCount' => $this->alerts->countOpen(),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(Alert $alert): Response
    {
        $serviceId = $alert->getServiceId();

        return $this->render('alerts/show.html.twig', [
            'alert' => $alert,
            'service' => null === $serviceId ? null : $this->services->find($serviceId),
            'notifications' => $this->notifications->findForAlert($alert->getId()),
        ]);
    }

    #[Route('/{id}/acknowledge', name: 'acknowledge', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function acknowledge(Alert $alert, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('alert_acknowledge_'.$alert->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $alert->acknowledge($this->actorId(), $this->clock->now());
        $this->alerts->save($alert);

        $this->addFlash('success', 'Aviso marcado como visto.');

        return $this->redirectToRoute('app_alerts_index');
    }

    #[Route('/{id}/dismiss', name: 'dismiss', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function dismiss(Alert $alert, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('alert_dismiss_'.$alert->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $alert->dismiss($this->actorId(), $this->clock->now());
        $this->alerts->save($alert);

        $this->addFlash('info', 'Aviso descartado. No volveremos a avisarte de lo mismo.');

        return $this->redirectToRoute('app_alerts_index');
    }

    #[Route('/preferences', name: 'preferences', methods: ['GET', 'POST'])]
    public function preferences(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Necesitas una sesión activa.');
        }

        $data = $this->buildPreferenceData($user);
        $form = $this->createForm(NotificationPreferenceFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $changed = ($this->updatePreferences)($user, $data, $user->getId());

            $this->addFlash(
                'success',
                0 === $changed
                    ? 'Tus preferencias ya estaban así.'
                    : sprintf('Preferencias guardadas. %d cambios.', $changed),
            );

            return $this->redirectToRoute('app_alerts_preferences');
        }

        return $this->render('alerts/preferences.html.twig', [
            'form' => $form,
            'types' => AlertType::cases(),
            'channels' => NotificationChannel::cases(),
        ]);
    }

    /**
     * Rellena la matriz con el valor **efectivo** de cada casilla: la
     * preferencia guardada si existe y, si no, el valor por defecto.
     */
    private function buildPreferenceData(User $user): NotificationPreferenceFormData
    {
        $stored = $this->preferences->findEnabledMapForUser($user->getId());
        $data = new NotificationPreferenceFormData();

        foreach (AlertType::cases() as $type) {
            foreach (NotificationChannel::cases() as $channel) {
                $key = sprintf('%s|%s', $type->value, $channel->value);
                $data->enabled[$type->value][$channel->value] = $stored[$key] ?? AlertRules::defaultEnabled($type, $channel);
            }
        }

        return $data;
    }

    private function actorId(): Uuid
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Necesitas una sesión activa.');
        }

        return $user->getId();
    }
}
