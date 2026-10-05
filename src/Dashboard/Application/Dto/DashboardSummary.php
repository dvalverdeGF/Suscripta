<?php

declare(strict_types=1);

namespace App\Dashboard\Application\Dto;

use App\Discovery\Domain\Entity\Discovery;
use App\Services\Domain\Dto\SpendPoint;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceStatus;
use App\Shared\Domain\ValueObject\Money;

use function count;

/**
 * Todo lo que el panel necesita para responder a las preguntas del producto
 * (PRODUCT.md §3): qué pago, cuánto me cuesta, cuándo me lo vuelven a cobrar,
 * qué se renueva y qué tengo pendiente de revisar.
 *
 * Es un DTO de solo lectura: la vista no consulta la base de datos ni calcula
 * nada por su cuenta.
 */
final readonly class DashboardSummary
{
    /**
     * @param array<string, Money>                           $monthlyByCurrency  clave = código ISO de la moneda
     * @param array<string, Money>                           $annualByCurrency
     * @param list<UpcomingWindow>                           $upcomingWindows    ventanas de 30 y 60 días
     * @param list<Service>                                  $upcomingRenewals
     * @param list<Discovery>                                $pendingDiscoveries
     * @param list<Service>                                  $recentPriceChanges
     * @param list<CategoryCost>                             $monthlyByCategory  ordenado de mayor a menor coste
     * @param list<Service>                                  $topServices        los más caros, por coste mensual
     * @param list<array{status: ServiceStatus, total: int}> $servicesByStatus
     * @param list<SpendPoint>                               $spendEvolution     del mes más antiguo al más reciente
     */
    public function __construct(
        public int $activeServices,
        public int $totalServices,
        public array $monthlyByCurrency,
        public array $annualByCurrency,
        public array $upcomingWindows,
        public array $upcomingRenewals,
        public array $pendingDiscoveries,
        public array $recentPriceChanges,
        public array $monthlyByCategory,
        public array $topServices,
        public array $servicesByStatus,
        public array $spendEvolution,
        public int $connectedMailboxes,
        public int $indexedMessages,
    ) {
    }

    public function hasAnyData(): bool
    {
        return $this->totalServices > 0 || $this->pendingDiscoveries !== [] || $this->connectedMailboxes > 0;
    }

    public function hasCosts(): bool
    {
        return $this->monthlyByCurrency !== [];
    }

    /**
     * Total mensual cuando todo el inventario está en la misma moneda.
     *
     * Con varias divisas no se devuelve nada: sumar euros con dólares daría un
     * número que no significa nada (ARCHITECTURE.md §10).
     */
    public function singleMonthlyTotal(): ?Money
    {
        return 1 === count($this->monthlyByCurrency) ? array_values($this->monthlyByCurrency)[0] : null;
    }

    public function singleAnnualTotal(): ?Money
    {
        return 1 === count($this->annualByCurrency) ? array_values($this->annualByCurrency)[0] : null;
    }

    /**
     * Ventana de cobros previstos de `$days` días, o null si no existe.
     */
    public function window(int $days): ?UpcomingWindow
    {
        foreach ($this->upcomingWindows as $window) {
            if ($window->days === $days) {
                return $window;
            }
        }

        return null;
    }
}
