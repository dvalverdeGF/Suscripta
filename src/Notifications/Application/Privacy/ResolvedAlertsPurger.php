<?php

declare(strict_types=1);

namespace App\Notifications\Application\Privacy;

use App\Notifications\Domain\Repository\AlertRepositoryInterface;
use App\Shared\Application\Privacy\PrivacySettings;
use App\Shared\Application\Privacy\RetentionPurgerInterface;
use DateTimeImmutable;

/**
 * Purga las alertas ya resueltas.
 *
 * Se conservan un año porque explican por qué se avisó al usuario de algo
 * concreto («¿por qué me saltó esa alerta en mayo?»). Pasado ese plazo,
 * siguen estando las métricas agregadas, pero no el detalle.
 */
final readonly class ResolvedAlertsPurger implements RetentionPurgerInterface
{
    public function __construct(
        private AlertRepositoryInterface $alerts,
        private PrivacySettings $settings,
    ) {
    }

    public function category(): string
    {
        return 'resolved_alerts';
    }

    public function purge(DateTimeImmutable $now): int
    {
        return $this->alerts->purgeResolvedBefore(
            $this->settings->resolvedAlertsThreshold($now),
            $this->settings->purgeBatchSize,
        );
    }
}
