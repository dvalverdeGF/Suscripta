<?php

declare(strict_types=1);

namespace App\Notifications\Application\Privacy;

use App\Notifications\Domain\Repository\AlertRepositoryInterface;
use App\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataProviderInterface;

use function count;

use Symfony\Component\Uid\Uuid;

/**
 * Sección «notifications» de la exportación: alertas, avisos y preferencias.
 *
 * Se exportan las tres cosas juntas porque forman una sola historia: qué se
 * avisó, cómo se avisó y qué prefiere el usuario que se le avise. Las
 * preferencias son la parte más personal: son la configuración de las
 * notificaciones de una persona concreta, y por eso se exportan con su
 * identificador de usuario.
 */
final readonly class NotificationsDataProvider implements OrganizationDataProviderInterface
{
    public function __construct(
        private AlertRepositoryInterface $alerts,
        private NotificationRepositoryInterface $notifications,
        private NotificationPreferenceRepositoryInterface $preferences,
    ) {
    }

    public function section(): string
    {
        return 'notifications';
    }

    public function export(Uuid $organizationId): array
    {
        $alertRows = [];

        foreach ($this->alerts->findAllForOrganization($organizationId) as $alert) {
            $alertRows[] = [
                'id' => $alert->getId()->toRfc4122(),
                'type' => $alert->getType()->value,
                'severity' => $alert->getSeverity()->value,
                'status' => $alert->getStatus()->value,
                'title' => $alert->getTitle(),
                'message' => $alert->getMessage(),
                'serviceId' => $alert->getServiceId()?->toRfc4122(),
                'discoveryId' => $alert->getDiscoveryId()?->toRfc4122(),
                'dueAt' => $alert->getDueAt()?->format('c'),
                'createdAt' => $alert->getCreatedAt()->format('c'),
                'resolvedAt' => $alert->getResolvedAt()?->format('c'),
            ];
        }

        $notificationRows = [];

        foreach ($this->notifications->findAllForOrganization($organizationId) as $notification) {
            $notificationRows[] = [
                'id' => $notification->getId()->toRfc4122(),
                'alertId' => $notification->getAlertId()->toRfc4122(),
                'userId' => $notification->getUserId()->toRfc4122(),
                'channel' => $notification->getChannel()->value,
                'status' => $notification->getStatus()->value,
                'sentAt' => $notification->getSentAt()?->format('c'),
                'createdAt' => $notification->getCreatedAt()->format('c'),
            ];
        }

        $preferenceRows = [];

        foreach ($this->preferences->findAllForOrganization($organizationId) as $preference) {
            $preferenceRows[] = [
                'id' => $preference->getId()->toRfc4122(),
                'userId' => $preference->getUserId()->toRfc4122(),
                'alertType' => $preference->getAlertType()->value,
                'channel' => $preference->getChannel()->value,
                'enabled' => $preference->isEnabled(),
                'updatedAt' => $preference->getUpdatedAt()->format('c'),
            ];
        }

        return [
            'meta' => [
                'alerts' => count($alertRows),
                'notifications' => count($notificationRows),
                'preferences' => count($preferenceRows),
            ],
            'rows' => $alertRows,
            'notifications' => $notificationRows,
            'preferences' => $preferenceRows,
        ];
    }
}
