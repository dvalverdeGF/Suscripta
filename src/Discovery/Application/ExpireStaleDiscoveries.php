<?php

declare(strict_types=1);

namespace App\Discovery\Application;

use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Domain\Enum\AuditAction;

use const DATE_ATOM;

/**
 * Caduca las propuestas que llevan demasiado tiempo sin que el usuario decida
 * nada (ARCHITECTURE.md §13.11).
 *
 * Caducar no es borrar: la propuesta pasa a `expired` y sale de la bandeja de
 * revisión, pero se conserva con su evidencia para el histórico y para el
 * aprendizaje del buzón (§13.12).
 *
 * Es idempotente: una propuesta ya caducada no vuelve a procesarse, porque el
 * repositorio solo devuelve las que siguen en `pending`.
 */
final readonly class ExpireStaleDiscoveries
{
    public function __construct(
        private DiscoveryRepositoryInterface $discoveries,
        private DiscoverySettings $settings,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
    ) {
    }

    /**
     * Caduca lo que haya caducado y devuelve cuántas propuestas se han
     * cerrado.
     */
    public function __invoke(int $limit = 100): int
    {
        $now = $this->clock->now();
        $threshold = $this->settings->expiryThreshold($now);
        $expired = 0;

        foreach ($this->discoveries->findExpired($threshold, $limit) as $discovery) {
            $discovery->expire($now);
            $this->discoveries->save($discovery, false);

            $this->auditLogger->log(
                action: AuditAction::DISCOVERY_EXPIRED,
                targetType: 'discovery',
                targetId: $discovery->getId()->toRfc4122(),
                metadata: [
                    'type' => $discovery->getType()->value,
                    'detectedAt' => $discovery->getDetectedAt()->format(DATE_ATOM),
                ],
            );

            ++$expired;
        }

        if ($expired > 0) {
            $this->discoveries->flush();
        }

        return $expired;
    }
}
