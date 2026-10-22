<?php

declare(strict_types=1);

namespace App\Ai\Application\Privacy;

use App\Ai\Domain\Repository\AiUsageRepositoryInterface;
use App\Shared\Application\Privacy\PrivacySettings;
use App\Shared\Application\Privacy\RetentionPurgerInterface;
use DateTimeImmutable;

/**
 * Purga el historial de llamadas a IA que ya ha caducado.
 *
 * Los importes agregados (cuánto se gastó por mes) no se guardan en esta
 * tabla: se calculan a partir de ella mientras existe, y el panel de
 * integración mantiene su propio resumen. Por eso purgar el detalle no
 * deja el panel sin cifras.
 */
final readonly class AiUsagePurger implements RetentionPurgerInterface
{
    public function __construct(
        private AiUsageRepositoryInterface $usage,
        private PrivacySettings $settings,
    ) {
    }

    public function category(): string
    {
        return 'ai_usage';
    }

    public function purge(DateTimeImmutable $now): int
    {
        return $this->usage->purgeOlderThan(
            $this->settings->aiUsageThreshold($now),
            $this->settings->purgeBatchSize,
        );
    }
}
