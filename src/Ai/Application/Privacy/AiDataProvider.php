<?php

declare(strict_types=1);

namespace App\Ai\Application\Privacy;

use App\Ai\Domain\Repository\AiBudgetRepositoryInterface;
use App\Ai\Domain\Repository\AiUsageRepositoryInterface;
use App\Ai\Domain\ValueObject\AiCost;
use App\Shared\Application\Privacy\OrganizationDataProviderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Sección «ai» de la exportación: el consumo de inteligencia artificial.
 *
 * Se exporta **qué** se usó y **cuánto costó**, nunca las claves. La clave de
 * la API es un secreto, y el informe de consumo no la necesita para nada.
 *
 * El desglose por propósito y por mes es lo que el usuario necesita para
 * decidir si el gasto tiene sentido: una llamada a un modelo caro cada
 * discoveredor no es lo mismo que una extracción al día.
 */
final readonly class AiDataProvider implements OrganizationDataProviderInterface
{
    public function __construct(
        private AiUsageRepositoryInterface $usage,
        private AiBudgetRepositoryInterface $budgets,
    ) {
    }

    public function section(): string
    {
        return 'ai';
    }

    public function export(Uuid $organizationId): array
    {
        $usages = $this->usage->findForOrganization($organizationId);

        $rows = [];
        $byMonth = [];
        $totalCost = 0;
        $totalCalls = 0;

        foreach ($usages as $usage) {
            $month = $usage->getCreatedAt()->format('Y-m');
            $cost = $usage->getCostMicro();

            $rows[] = [
                'id' => $usage->getId()->toRfc4122(),
                'providerKey' => $usage->getProviderKey(),
                'purpose' => $usage->getPurpose()->value,
                'tier' => $usage->getTier()->value,
                'model' => $usage->getModel(),
                'promptTokens' => $usage->getPromptTokens(),
                'completionTokens' => $usage->getCompletionTokens(),
                'costMicro' => $cost,
                'costMinor' => AiCost::toMinor($cost),
                'durationMs' => $usage->getDurationMs(),
                'entityType' => $usage->getEntityType(),
                'entityId' => $usage->getEntityId()?->toRfc4122(),
                'createdAt' => $usage->getCreatedAt()->format('c'),
            ];

            $byMonth[$month] = ($byMonth[$month] ?? 0) + $cost;
            $totalCost += $cost;
            ++$totalCalls;
        }

        ksort($byMonth);

        $budgetRows = [];

        foreach ($this->budgets->findForOrganization($organizationId) as $budget) {
            $budgetRows[] = [
                'period' => $budget->getPeriod(),
                'limitMinor' => $budget->getLimitMinor(),
                'mode' => $budget->getMode()->value,
                'updatedAt' => $budget->getUpdatedAt()->format('c'),
            ];
        }

        return [
            'meta' => [
                'calls' => $totalCalls,
                'totalCostMicro' => $totalCost,
                'totalCostMinor' => AiCost::toMinor($totalCost),
                'costByMonth' => $byMonth,
                'budgets' => $budgetRows,
            ],
            'rows' => $rows,
        ];
    }
}
