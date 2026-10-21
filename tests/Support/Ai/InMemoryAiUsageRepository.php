<?php

declare(strict_types=1);

namespace App\Tests\Support\Ai;

use App\Ai\Domain\Entity\AiUsage;
use App\Ai\Domain\Repository\AiUsageRepositoryInterface;
use App\Shared\Domain\ValueObject\DateRange;

use function array_filter;
use function array_slice;
use function array_values;
use function count;

use Symfony\Component\Uid\Uuid;

use function usort;

/**
 * Registro de uso de IA en memoria.
 *
 * Existe para poder probar el presupuesto y el coste sin tocar la base de
 * datos: lo que se comprueba es el cálculo y la consulta, no el SQL.
 */
final class InMemoryAiUsageRepository implements AiUsageRepositoryInterface
{
    /**
     * @var list<AiUsage>
     */
    private array $usages = [];

    public function save(AiUsage $usage, bool $flush = true): void
    {
        $this->usages[] = $usage;
    }

    public function totalCostMinor(Uuid $organizationId, DateRange $period): int
    {
        $total = 0;

        foreach ($this->usages as $usage) {
            if ($this->matches($usage, $organizationId, $period)) {
                $total += $usage->getCostMinor();
            }
        }

        return $total;
    }

    public function costByProvider(Uuid $organizationId, DateRange $period): array
    {
        $costs = [];

        foreach ($this->usages as $usage) {
            if (!$this->matches($usage, $organizationId, $period)) {
                continue;
            }

            $key = $usage->getProviderKey();
            $costs[$key] = ($costs[$key] ?? 0) + $usage->getCostMinor();
        }

        return $costs;
    }

    public function costByPurpose(Uuid $organizationId, DateRange $period): array
    {
        $costs = [];

        foreach ($this->usages as $usage) {
            if (!$this->matches($usage, $organizationId, $period)) {
                continue;
            }

            $key = $usage->getPurpose()->value;
            $costs[$key] = ($costs[$key] ?? 0) + $usage->getCostMinor();
        }

        return $costs;
    }

    public function usageSummary(Uuid $organizationId, DateRange $period): array
    {
        $summary = ['calls' => 0, 'promptTokens' => 0, 'completionTokens' => 0, 'failures' => 0];

        foreach ($this->usages as $usage) {
            if (!$this->matches($usage, $organizationId, $period)) {
                continue;
            }

            ++$summary['calls'];
            $summary['promptTokens'] += $usage->getPromptTokens();
            $summary['completionTokens'] += $usage->getCompletionTokens();

            if (!$usage->isSuccessful()) {
                ++$summary['failures'];
            }
        }

        return $summary;
    }

    public function recent(Uuid $organizationId, int $limit = 50): array
    {
        $filtered = array_values(array_filter(
            $this->usages,
            static fn (AiUsage $usage): bool => $usage->getOrganizationId()->equals($organizationId),
        ));

        usort(
            $filtered,
            static fn (AiUsage $a, AiUsage $b): int => $b->getCreatedAt() <=> $a->getCreatedAt(),
        );

        return array_slice($filtered, 0, $limit);
    }

    /**
     * Todos los usos guardados, para las aserciones.
     *
     * @return list<AiUsage>
     */
    public function all(): array
    {
        return $this->usages;
    }

    public function count(): int
    {
        return count($this->usages);
    }

    private function matches(AiUsage $usage, Uuid $organizationId, DateRange $period): bool
    {
        return $usage->getOrganizationId()->equals($organizationId)
            && $period->contains($usage->getCreatedAt());
    }
}
