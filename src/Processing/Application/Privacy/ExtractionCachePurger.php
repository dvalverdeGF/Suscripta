<?php

declare(strict_types=1);

namespace App\Processing\Application\Privacy;

use App\Processing\Domain\Repository\ExtractionCacheRepositoryInterface;
use App\Shared\Application\Privacy\PrivacySettings;
use App\Shared\Application\Privacy\RetentionPurgerInterface;
use DateTimeImmutable;

/**
 * Purga la caché de extracción.
 *
 * La caché existe para no repetir el análisis del mismo documento. Si lleva
 * un mes sin usarse, es basura: se regeneraría en el siguiente análisis.
 */
final readonly class ExtractionCachePurger implements RetentionPurgerInterface
{
    public function __construct(
        private ExtractionCacheRepositoryInterface $cache,
        private PrivacySettings $settings,
    ) {
    }

    public function category(): string
    {
        return 'extraction_cache';
    }

    public function purge(DateTimeImmutable $now): int
    {
        return $this->cache->purgeStale(
            $this->settings->extractionCacheThreshold($now),
            $this->settings->purgeBatchSize,
        );
    }
}
