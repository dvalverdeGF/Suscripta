<?php

declare(strict_types=1);

namespace App\Notifications\Application;

use App\Identity\Domain\Entity\User;
use App\Notifications\Application\Dto\NotificationPreferenceFormData;
use App\Notifications\Domain\Entity\NotificationPreference;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Enum\NotificationChannel;
use App\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notifications\Domain\Service\AlertRules;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use Symfony\Component\Uid\Uuid;

/**
 * Guarda qué avisos quiere recibir el usuario y por dónde (ARCHITECTURE.md §4.7).
 *
 * Solo se persisten las **desviaciones** del valor por defecto. Si el usuario
 * deja una casilla como venía, no se guarda nada; si vuelve al valor por
 * defecto, se borra la fila. Así, cambiar los valores por defecto del producto
 * (o añadir un tipo de aviso) no obliga a migrar las preferencias de nadie.
 */
final readonly class UpdateNotificationPreferences
{
    public function __construct(
        private NotificationPreferenceRepositoryInterface $preferences,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
    ) {
    }

    /**
     * @return int número de preferencias que han cambiado
     */
    public function __invoke(User $user, NotificationPreferenceFormData $data, ?Uuid $actorUserId = null): int
    {
        $organizationId = $this->tenantContext->requireOrganizationId();
        $now = $this->clock->now();
        $changed = 0;

        foreach (AlertType::cases() as $type) {
            foreach (NotificationChannel::cases() as $channel) {
                $default = AlertRules::defaultEnabled($type, $channel);
                $desired = $data->enabled[$type->value][$channel->value] ?? $default;
                $existing = $this->preferences->findOne($user->getId(), $type, $channel);

                if ($desired === $default) {
                    if (null !== $existing) {
                        $this->preferences->remove($existing);
                        ++$changed;
                    }

                    continue;
                }

                if (null === $existing) {
                    $this->preferences->save(new NotificationPreference(
                        organizationId: $organizationId,
                        userId: $user->getId(),
                        alertType: $type,
                        channel: $channel,
                        enabled: $desired,
                        updatedAt: $now,
                    ));
                    ++$changed;

                    continue;
                }

                if ($existing->isEnabled() !== $desired) {
                    $existing->setEnabled($desired, $now);
                    $this->preferences->save($existing);
                    ++$changed;
                }
            }
        }

        if ($changed > 0) {
            $this->auditLogger->log(
                action: AuditAction::NOTIFICATION_PREFERENCES_UPDATED,
                targetType: 'user',
                targetId: $user->getId()->toRfc4122(),
                metadata: ['changed' => $changed],
                actorUserId: $actorUserId,
            );
        }

        return $changed;
    }
}
