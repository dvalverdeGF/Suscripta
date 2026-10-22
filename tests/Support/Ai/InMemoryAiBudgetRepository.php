<?php

declare(strict_types=1);

namespace App\Tests\Support\Ai;

use App\Ai\Domain\Entity\AiBudget;
use App\Ai\Domain\Repository\AiBudgetRepositoryInterface;

use function array_filter;
use function array_values;

use Symfony\Component\Uid\Uuid;

/**
 * El presupuesto de IA en memoria.
 *
 * Sustituye a la tabla `ai_budget` en las pruebas: lo que se comprueba es la
 * política que aplica el `AiBudgetGuard` (tope, modo, gasto), no el SQL.
 */
final class InMemoryAiBudgetRepository implements AiBudgetRepositoryInterface
{
    /**
     * @var array<string, AiBudget> organización + periodo => presupuesto
     */
    private array $budgets = [];

    public function find(Uuid $organizationId, string $period): ?AiBudget
    {
        return $this->budgets[$this->key($organizationId, $period)] ?? null;
    }

    public function save(AiBudget $budget, bool $flush = true): void
    {
        $this->budgets[$this->key($budget->getOrganizationId(), $budget->getPeriod())] = $budget;
    }

    public function flush(): void
    {
    }

    /**
     * Todos los presupuestos guardados, para las aserciones.
     *
     * @return list<AiBudget>
     */
    public function all(): array
    {
        return array_values($this->budgets);
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        $removed = 0;

        foreach ($this->budgets as $key => $budget) {
            if (!$budget->getOrganizationId()->equals($organizationId)) {
                continue;
            }

            unset($this->budgets[$key]);
            ++$removed;
        }

        return $removed;
    }

    /**
     * @return list<AiBudget>
     */
    public function findForOrganization(Uuid $organizationId): array
    {
        return array_values(array_filter(
            $this->budgets,
            static fn (AiBudget $budget): bool => $budget->getOrganizationId()->equals($organizationId),
        ));
    }

    private function key(Uuid $organizationId, string $period): string
    {
        return $organizationId->toRfc4122().'|'.$period;
    }
}
