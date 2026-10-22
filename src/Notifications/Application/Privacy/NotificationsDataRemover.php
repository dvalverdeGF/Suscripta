<?php

declare(strict_types=1);

namespace App\Notifications\Application\Privacy;

use App\Notifications\Domain\Repository\AlertRepositoryInterface;
use App\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataRemoverInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Borra alertas, notificaciones enviadas y preferencias de una organización.
 *
 * El orden es el inverso al de creación: primero las notificaciones (que
 * apuntan a una alerta), después las alertas y por último las preferencias.
 */
final readonly class NotificationsDataRemover implements OrganizationDataRemoverInterface
{
    public function __construct(
        private NotificationRepositoryInterface $notifications,
        private AlertRepositoryInterface $alerts,
        private NotificationPreferenceRepositoryInterface $preferences,
    ) {
    }

    public function module(): string
    {
        return 'notifications';
    }

    /**
     * Las alertas apuntan a descubrimientos y servicios: se van antes que
     * ellos.
     */
    public function priority(): int
    {
        return 30;
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        $notificationsRemoved = $this->notifications->removeForOrganization($organizationId);
        $alertsRemoved = $this->alerts->removeForOrganization($organizationId);
        $preferencesRemoved = $this->preferences->removeForOrganization($organizationId);

        return $notificationsRemoved + $alertsRemoved + $preferencesRemoved;
    }
}
