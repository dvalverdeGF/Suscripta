<?php

declare(strict_types=1);

namespace App\Tests\Support\Processing;

use App\Processing\Domain\Entity\ExtractionCache;
use App\Processing\Domain\Repository\ExtractionCacheRepositoryInterface;

use function array_slice;
use function array_values;
use function count;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Caché de extracción en memoria.
 *
 * Guarda las **mismas instancias** que recibe, no copias: así un test puede
 * comprobar el contador de aciertos después de que el pipeline haya usado la
 * caché, que es justo lo que hay que verificar.
 */
final class InMemoryExtractionCacheRepository implements ExtractionCacheRepositoryInterface
{
    /** @var array<string, ExtractionCache> */
    private array $entries = [];

    public int $flushCount = 0;

    public function findForContentHash(string $contentHash): ?ExtractionCache
    {
        foreach ($this->entries as $entry) {
            if ($entry->getContentHash() === $contentHash) {
                return $entry;
            }
        }

        return null;
    }

    public function save(ExtractionCache $cache, bool $flush = true): void
    {
        $this->entries[$cache->getId()->toRfc4122()] = $cache;

        if ($flush) {
            ++$this->flushCount;
        }
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        $removed = 0;

        foreach ($this->entries as $key => $entry) {
            if ($entry->getOrganizationId()->toRfc4122() === $organizationId->toRfc4122()) {
                unset($this->entries[$key]);
                ++$removed;
            }
        }

        return $removed;
    }

    public function countForOrganization(): int
    {
        return count($this->entries);
    }

    public function findStaleBefore(DateTimeImmutable $threshold, int $limit = 500): array
    {
        $stale = [];

        foreach ($this->entries as $entry) {
            if ($entry->getLastUsedAt() < $threshold) {
                $stale[] = $entry;
            }
        }

        return array_slice($stale, 0, $limit);
    }

    /** @return list<ExtractionCache> */
    public function all(): array
    {
        return array_values($this->entries);
    }
}
