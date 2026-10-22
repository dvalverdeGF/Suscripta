<?php

declare(strict_types=1);

namespace App\Api\Application\ReadModel;

use App\Dashboard\Application\BuildDashboardSummary;
use App\Dashboard\Application\Dto\DashboardSummary;
use App\Shared\Domain\ValueObject\Money;

/**
 * Métricas de la organización: lo que se paga, cuánto hay y qué queda
 * pendiente.
 *
 * Reutiliza el mismo resumen que construye el panel: así la API y la web
 * nunca pueden discrepar sobre el mismo número. Si el panel dice «12 servicios
 * activos» y la API dice 11, alguno de los dos está mintiendo, y eso sería
 * un bug de verdad.
 */
final readonly class GetMetrics
{
    public function __construct(private BuildDashboardSummary $summary)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $summary = ($this->summary)();

        return [
            'active_services' => $summary->activeServices,
            'total_services' => $summary->totalServices,
            'monthly_by_currency' => self::moneyMap($summary->monthlyByCurrency),
            'annual_by_currency' => self::moneyMap($summary->annualByCurrency),
            'open_alerts' => $summary->openAlerts,
            'pending_discoveries' => count($summary->pendingDiscoveries),
            'connected_mailboxes' => $summary->connectedMailboxes,
            'indexed_messages' => $summary->indexedMessages,
            'services_by_status' => $this->servicesByStatus($summary),
            'spend_evolution' => $this->spendEvolution($summary),
            'monthly_by_category' => $this->monthlyByCategory($summary),
        ];
    }

    /**
     * @param array<string, Money> $money
     *
     * @return array<string, array<string, mixed>>
     */
    private static function moneyMap(array $money): array
    {
        $result = [];

        foreach ($money as $currency => $amount) {
            $result[$currency] = ResourceMapper::money($amount);
        }

        return $result;
    }

    /**
     * @return list<array{status: string, total: int}>
     */
    private function servicesByStatus(DashboardSummary $summary): array
    {
        $result = [];

        foreach ($summary->servicesByStatus as $row) {
            $result[] = [
                'status' => $row['status']->value,
                'total' => $row['total'],
            ];
        }

        return $result;
    }

    /**
     * @return list<array{month: string, by_currency: array<string, array<string, mixed>>, service_count: int}>
     */
    private function spendEvolution(DashboardSummary $summary): array
    {
        $result = [];

        foreach ($summary->spendEvolution as $point) {
            $result[] = [
                'month' => $point->month->format('Y-m'),
                'by_currency' => self::moneyMap($point->byCurrency),
                'service_count' => $point->serviceCount,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{category: string, monthly_by_currency: array<string, array<string, mixed>>, service_count: int}>
     */
    private function monthlyByCategory(DashboardSummary $summary): array
    {
        $result = [];

        foreach ($summary->monthlyByCategory as $category) {
            $result[] = [
                'category' => $category->categoryName,
                'monthly_by_currency' => self::moneyMap($category->monthlyByCurrency),
                'service_count' => $category->serviceCount,
            ];
        }

        return $result;
    }
}
