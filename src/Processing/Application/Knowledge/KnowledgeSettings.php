<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge;

use DateTimeImmutable;

use function sprintf;

/**
 * Parámetros del conocimiento aprendido (config/packages/knowledge.yaml).
 *
 * Son política de producto: cuánto tiempo se recuerda lo aprendido y cuántas
 * entradas puede tener un buzón antes de que empiece a sobrar.
 */
final readonly class KnowledgeSettings
{
    public function __construct(
        public int $staleDays = 90,
        public int $maxEntriesPerAccount = 500,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            staleDays: (int) ($config['stale_days'] ?? 90),
            maxEntriesPerAccount: (int) ($config['max_entries_per_account'] ?? 500),
        );
    }

    /**
     * Desde cuándo una entrada que nunca se ha usado se considera sobrante.
     */
    public function staleThreshold(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d days', $this->staleDays));
    }
}
