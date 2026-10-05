<?php

declare(strict_types=1);

namespace App\Notifications\Application;

use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Notifications\Application\Channel\NotificationChannelRegistry;
use App\Notifications\Application\Dto\NotificationDispatchResult;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Enum\NotificationChannel;
use App\Notifications\Domain\Enum\NotificationStatus;
use App\Notifications\Domain\Repository\AlertRepositoryInterface;
use App\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Notifications\Domain\Service\AlertRules;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;

use function sprintf;

/**
 * Entrega los avisos abiertos por los canales que correspondan (ARCHITECTURE.md §4.7).
 *
 * Es idempotente por la misma razón que el generador: cada entrega lleva una
 * clave de deduplicación (aviso + usuario + canal), así que ejecutar el comando
 * cada hora no multiplica los correos. Un envío **fallido** sí se reintenta; uno
 * ya entregado, no.
 *
 * La política de qué se entrega vive aquí y no en los canales: los canales solo
 * saben enviar. Así, añadir un canal nuevo no obliga a repetir las reglas de
 * preferencias y severidad.
 */
final readonly class DispatchNotifications
{
    public function __construct(
        private AlertRepositoryInterface $alerts,
        private NotificationRepositoryInterface $notifications,
        private NotificationPreferenceRepositoryInterface $preferences,
        private OrganizationRepositoryInterface $organizations,
        private NotificationChannelRegistry $channels,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
    ) {
    }

    public function __invoke(int $limit = 50): NotificationDispatchResult
    {
        $organizationId = $this->tenantContext->requireOrganizationId();
        $now = $this->clock->now();

        $members = $this->organizations->findMembers($organizationId);

        if ([] === $members) {
            return new NotificationDispatchResult();
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $alreadyDelivered = 0;

        foreach ($this->alerts->findOpen($limit) as $alert) {
            foreach ($members as $member) {
                $user = $member['user'];
                $enabled = $this->preferences->findEnabledMapForUser($user->getId());

                foreach ($this->channels->all() as $channel) {
                    $channelEnum = $channel->channel();

                    if (!$this->isEnabled($enabled, $alert, $channelEnum)) {
                        continue;
                    }

                    $dedupKey = Notification::buildDedupKey($alert->getId(), $user->getId(), $channelEnum);
                    $existing = $this->notifications->findByDedupKey($dedupKey);

                    if (null !== $existing && NotificationStatus::FAILED !== $existing->getStatus()) {
                        ++$alreadyDelivered;

                        continue;
                    }

                    $notification = $existing ?? new Notification(
                        organizationId: $organizationId,
                        alertId: $alert->getId(),
                        userId: $user->getId(),
                        channel: $channelEnum,
                        dedupKey: $dedupKey,
                        createdAt: $now,
                    );

                    $result = $channel->deliver($alert, $user);

                    match ($result->status) {
                        NotificationStatus::SENT => $notification->markSent($now),
                        NotificationStatus::SKIPPED => $notification->markSkipped($now, $result->detail ?? 'Sin motivo indicado.'),
                        default => $notification->markFailed($now, $result->detail ?? 'Error desconocido.'),
                    };

                    $this->notifications->save($notification);

                    if ($result->isFailure()) {
                        ++$failed;
                        $this->auditLogger->log(
                            action: AuditAction::NOTIFICATION_FAILED,
                            targetType: 'alert',
                            targetId: $alert->getId()->toRfc4122(),
                            metadata: ['channel' => $channelEnum->value, 'error' => $result->detail],
                        );

                        continue;
                    }

                    if (NotificationStatus::SKIPPED === $result->status) {
                        ++$skipped;

                        continue;
                    }

                    ++$sent;

                    // Solo se audita lo que sale del sistema: un aviso de
                    // bandeja no es un acceso a datos de terceros.
                    if (NotificationChannel::EMAIL === $channelEnum) {
                        $this->auditLogger->log(
                            action: AuditAction::NOTIFICATION_SENT,
                            targetType: 'alert',
                            targetId: $alert->getId()->toRfc4122(),
                            metadata: ['channel' => $channelEnum->value, 'userId' => $user->getId()->toRfc4122()],
                        );
                    }
                }
            }
        }

        return new NotificationDispatchResult($sent, $skipped, $failed, $alreadyDelivered);
    }

    /**
     * ¿Se entrega este aviso por este canal?
     *
     * Hay dos reglas y el orden importa:
     *
     * 1. **Lo urgente siempre sale por correo.** No es configurable: si el
     *    usuario pudiera silenciar un cobro a tres días, el producto fallaría
     *    justo en el caso para el que existe.
     * 2. Para todo lo demás manda la preferencia del usuario y, si no ha dicho
     *    nada, el valor por defecto del tipo.
     *
     * @param array<string, bool> $enabled mapa «tipo|canal» de las desviaciones del usuario
     */
    private function isEnabled(array $enabled, Alert $alert, NotificationChannel $channel): bool
    {
        if (NotificationChannel::EMAIL === $channel && $alert->getSeverity()->warrantsEmail()) {
            return true;
        }

        $key = sprintf('%s|%s', $alert->getType()->value, $channel->value);

        return $enabled[$key] ?? AlertRules::defaultEnabled($alert->getType(), $channel);
    }
}
