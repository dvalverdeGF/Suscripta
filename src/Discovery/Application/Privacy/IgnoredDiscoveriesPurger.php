<?php

declare(strict_types=1);

namespace App\Discovery\Application\Privacy;

use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Shared\Application\Privacy\PrivacySettings;
use App\Shared\Application\Privacy\RetentionPurgerInterface;
use DateTimeImmutable;

/**
 * Purga los descubrimientos que el usuario descartó hace tiempo.
 *
 * Solo se purgan los **ignorados** (los que el usuario marcó como «esto no
 * me interesa»): un descubrimiento pendiente o confirmado sigue siendo
 * información viva, y uno editado es el resultado de una corrección del
 * usuario, que no se pierde.
 */
final readonly class IgnoredDiscoveriesPurger implements RetentionPurgerInterface
{
    public function __construct(
        private DiscoveryRepositoryInterface $discoveries,
        private PrivacySettings $settings,
    ) {
    }

    public function category(): string
    {
        return 'ignored_discoveries';
    }

    public function purge(DateTimeImmutable $now): int
    {
        return $this->discoveries->purgeIgnoredBefore(
            $this->settings->ignoredDiscoveriesThreshold($now),
            $this->settings->purgeBatchSize,
        );
    }
}
