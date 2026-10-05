<?php

declare(strict_types=1);

namespace App\Tests\Support\Catalog;

use App\Catalog\Domain\Entity\ProviderParser;
use App\Catalog\Domain\Repository\ProviderParserRepositoryInterface;
use Symfony\Component\Uid\Uuid;

use function array_values;
use function usort;

/**
 * Doble en memoria de `ProviderParserRepositoryInterface`.
 *
 * Reproduce el orden real de `findEnabledForProvider()` —versión descendente—
 * porque el pipeline depende de él: la plantilla más nueva se prueba primero.
 */
final class InMemoryProviderParserRepository implements ProviderParserRepositoryInterface
{
    /** @var array<string, ProviderParser> */
    private array $parsers = [];

    public int $flushCount = 0;

    public function add(ProviderParser $parser): void
    {
        $this->parsers[$parser->getId()->toRfc4122()] = $parser;
    }

    /**
     * @return list<ProviderParser>
     */
    public function all(): array
    {
        return array_values($this->parsers);
    }

    public function find(Uuid $id): ?ProviderParser
    {
        return $this->parsers[$id->toRfc4122()] ?? null;
    }

    public function findEnabledForProvider(Uuid $providerId): array
    {
        $found = [];

        foreach ($this->parsers as $parser) {
            if ($parser->isEnabled() && $parser->getProvider()->getId()->equals($providerId)) {
                $found[] = $parser;
            }
        }

        usort($found, static fn (ProviderParser $a, ProviderParser $b): int => $b->getVersion() <=> $a->getVersion());

        return $found;
    }

    public function findForProvider(Uuid $providerId): array
    {
        $found = [];

        foreach ($this->parsers as $parser) {
            if ($parser->getProvider()->getId()->equals($providerId)) {
                $found[] = $parser;
            }
        }

        return $found;
    }

    public function save(ProviderParser $parser, bool $flush = true): void
    {
        $this->parsers[$parser->getId()->toRfc4122()] = $parser;

        if ($flush) {
            ++$this->flushCount;
        }
    }
}
