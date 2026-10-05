<?php

declare(strict_types=1);

namespace App\Dashboard\Application;

use App\Catalog\Domain\Repository\CategoryRepositoryInterface;
use App\Dashboard\Application\Dto\CategoryCost;
use App\Dashboard\Application\Dto\DashboardSummary;
use App\Dashboard\Application\Dto\UpcomingWindow;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Notifications\Domain\Repository\AlertRepositoryInterface;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Services\Domain\Service\ServiceCostCalculator;
use App\Services\Domain\Service\SpendEvolution;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\Money;

use function array_slice;
use function count;

use DateTimeImmutable;

use function usort;

/**
 * Construye el resumen del panel.
 *
 * El panel es la respuesta a la promesa del producto: «conecta tu correo y
 * descubre automáticamente todo lo que estás pagando». Por eso lo primero que
 * se calcula es el coste, y lo segundo lo que está pendiente de decidir: un
 * descubrimiento sin revisar es valor que el usuario todavía no ha visto.
 *
 * **Todo se calcula sobre la lista de servicios ya cargada.** El panel hace un
 * número fijo de consultas (servicios, categorías, buzones, recuento de
 * mensajes, descubrimientos) independientemente de cuántos servicios tenga la
 * organización: no hay una consulta por servicio ni por buzón.
 */
final readonly class BuildDashboardSummary
{
    /** Ventanas de cobros previstos, en días. */
    private const UPCOMING_WINDOWS = [30, 60];
    private const UPCOMING_LIMIT = 5;
    private const RENEWAL_WINDOW_DAYS = 30;
    private const PENDING_LIMIT = 5;
    private const PRICE_CHANGE_WINDOW_DAYS = 90;
    private const TOP_SERVICES_LIMIT = 5;
    private const SPEND_EVOLUTION_MONTHS = 6;

    public function __construct(
        private ServiceRepositoryInterface $services,
        private DiscoveryRepositoryInterface $discoveries,
        private EmailAccountRepositoryInterface $accounts,
        private EmailMessageRepositoryInterface $messages,
        private AlertRepositoryInterface $alerts,
        private CategoryRepositoryInterface $categories,
        private ServiceCostCalculator $costs,
        private SpendEvolution $evolution,
        private TenantContext $tenantContext,
        private Clock $clock,
    ) {
    }

    public function __invoke(): DashboardSummary
    {
        $now = $this->clock->now();
        $services = $this->services->findForOrganization();

        $accounts = $this->accounts->findForOrganization();
        $messageCounts = $this->messages->countByAccount();
        $indexedMessages = 0;

        foreach ($accounts as $account) {
            $indexedMessages += $messageCounts[$account->getId()->toRfc4122()] ?? 0;
        }

        return new DashboardSummary(
            activeServices: count(array_filter($services, static fn (Service $service): bool => $service->getStatus()->countsTowardsRecurringCost())),
            totalServices: count($services),
            monthlyByCurrency: $this->costs->totalMonthlyByCurrency($services),
            annualByCurrency: $this->costs->totalAnnualByCurrency($services),
            upcomingWindows: $this->upcomingWindows($services, $now),
            upcomingRenewals: $this->upcomingRenewals($services, $now),
            pendingDiscoveries: $this->discoveries->findPending(self::PENDING_LIMIT),
            openAlerts: $this->alerts->countOpen(),
            recentPriceChanges: $this->recentPriceChanges($services, $now),
            monthlyByCategory: $this->monthlyByCategory($services),
            topServices: $this->topServices($services),
            servicesByStatus: $this->servicesByStatus($services),
            spendEvolution: $this->evolution->monthly(
                $services,
                $now->modify('-'.(self::SPEND_EVOLUTION_MONTHS - 1).' months'),
                $now,
            ),
            connectedMailboxes: count($accounts),
            indexedMessages: $indexedMessages,
        );
    }

    /**
     * Cobros previstos en cada ventana, con su total por moneda.
     *
     * Se calcula en memoria a partir de la lista de servicios en lugar de
     * lanzar una consulta por ventana: el panel ya tiene todos los servicios
     * cargados y una consulta más solo añadiría latencia.
     *
     * @param list<Service> $services
     *
     * @return list<UpcomingWindow>
     */
    private function upcomingWindows(array $services, DateTimeImmutable $now): array
    {
        $windows = [];

        foreach (self::UPCOMING_WINDOWS as $days) {
            $until = $now->modify('+'.$days.' days');
            $charges = [];

            foreach ($services as $service) {
                if (!$service->getStatus()->countsTowardsRecurringCost()) {
                    continue;
                }

                $nextChargeAt = $service->getNextChargeAt();

                if (null === $nextChargeAt || $nextChargeAt > $until) {
                    continue;
                }

                $charges[] = $service;
            }

            usort(
                $charges,
                static fn (Service $a, Service $b): int => $a->getNextChargeAt() <=> $b->getNextChargeAt(),
            );

            $windows[] = new UpcomingWindow(
                days: $days,
                charges: array_slice($charges, 0, self::UPCOMING_LIMIT),
                totalsByCurrency: $this->sumChargesByCurrency($charges),
            );
        }

        return $windows;
    }

    /**
     * Suma de los importes que se van a cobrar, agrupada por moneda.
     *
     * Se suma el importe del próximo cobro, no el equivalente mensual: la
     * pregunta que responde esta ventana es «cuánto me van a cobrar», no
     * «cuánto me cuesta al mes».
     *
     * @param list<Service> $charges
     *
     * @return array<string, Money>
     */
    private function sumChargesByCurrency(array $charges): array
    {
        $totals = [];

        foreach ($charges as $service) {
            $amount = $service->getCurrentAmount();

            if (null === $amount) {
                continue;
            }

            $code = $amount->currency->value;
            $totals[$code] = isset($totals[$code]) ? $totals[$code]->add($amount) : $amount;
        }

        return $totals;
    }

    /**
     * @param list<Service> $services
     *
     * @return list<Service>
     */
    private function upcomingRenewals(array $services, DateTimeImmutable $now): array
    {
        $until = $now->modify('+'.self::RENEWAL_WINDOW_DAYS.' days');
        $renewals = [];

        foreach ($services as $service) {
            if (!$service->getStatus()->countsTowardsRecurringCost()) {
                continue;
            }

            $renewalAt = $service->getRenewalAt();

            if (null === $renewalAt || $renewalAt > $until) {
                continue;
            }

            $renewals[] = $service;
        }

        usort(
            $renewals,
            static fn (Service $a, Service $b): int => $a->getRenewalAt() <=> $b->getRenewalAt(),
        );

        return array_slice($renewals, 0, self::UPCOMING_LIMIT);
    }

    /**
     * Coste mensual equivalente agrupado por categoría.
     *
     * Los servicios sin categoría se agrupan bajo «Sin categoría» en lugar de
     * desaparecer: si no aparecen, el desglose no cuadra con el total y el
     * usuario deja de fiarse de él.
     *
     * @param list<Service> $services
     *
     * @return list<CategoryCost>
     */
    private function monthlyByCategory(array $services): array
    {
        $names = $this->categoryNames();
        $totals = [];
        $counts = [];

        foreach ($services as $service) {
            if (!$service->getStatus()->countsTowardsRecurringCost()) {
                continue;
            }

            $monthly = $this->costs->monthlyEquivalent($service);

            if (null === $monthly) {
                continue;
            }

            $categoryId = $service->getCategoryId();
            $key = null === $categoryId ? '' : $categoryId->toRfc4122();
            $label = $names[$key] ?? 'Sin categoría';
            $code = $monthly->currency->value;

            $current = $totals[$label][$code] ?? null;
            $totals[$label][$code] = null === $current ? $monthly : $current->add($monthly);
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        $breakdown = [];

        foreach ($totals as $label => $byCurrency) {
            $breakdown[] = new CategoryCost(
                categoryName: $label,
                monthlyByCurrency: $byCurrency,
                serviceCount: $counts[$label] ?? 0,
            );
        }

        usort(
            $breakdown,
            static fn (CategoryCost $a, CategoryCost $b): int => $b->sortWeight() <=> $a->sortWeight(),
        );

        return $breakdown;
    }

    /**
     * Los servicios que más cuestan al mes.
     *
     * @param list<Service> $services
     *
     * @return list<Service>
     */
    private function topServices(array $services): array
    {
        $ranked = [];

        foreach ($services as $service) {
            if (!$service->getStatus()->countsTowardsRecurringCost()) {
                continue;
            }

            $monthly = $this->costs->monthlyEquivalent($service);

            if (null === $monthly) {
                continue;
            }

            $ranked[] = ['service' => $service, 'monthly' => $monthly];
        }

        usort(
            $ranked,
            static fn (array $a, array $b): int => $b['monthly']->amountMinor <=> $a['monthly']->amountMinor,
        );

        return array_map(
            static fn (array $row): Service => $row['service'],
            array_slice($ranked, 0, self::TOP_SERVICES_LIMIT),
        );
    }

    /**
     * Recuento de servicios por estado, en el orden de declaración del enum
     * para que la vista no tenga que ordenar nada.
     *
     * @param list<Service> $services
     *
     * @return list<array{status: ServiceStatus, total: int}>
     */
    private function servicesByStatus(array $services): array
    {
        $counts = [];

        foreach ($services as $service) {
            $value = $service->getStatus()->value;
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        $rows = [];

        foreach (ServiceStatus::cases() as $status) {
            if (!isset($counts[$status->value])) {
                continue;
            }

            $rows[] = ['status' => $status, 'total' => $counts[$status->value]];
        }

        return $rows;
    }

    /**
     * Servicios cuyo precio ha cambiado en los últimos meses.
     *
     * Es la respuesta a «¿qué ha cambiado?»: un cambio de precio silencioso es
     * exactamente lo que el usuario no habría detectado por su cuenta.
     *
     * @param list<Service> $services
     *
     * @return list<Service>
     */
    private function recentPriceChanges(array $services, DateTimeImmutable $now): array
    {
        $threshold = $now->modify('-'.self::PRICE_CHANGE_WINDOW_DAYS.' days');
        $changed = [];

        foreach ($services as $service) {
            $current = $service->getCurrentPrice();

            if (null === $current || $current->getValidFrom() < $threshold) {
                continue;
            }

            if (null !== $this->costs->priceChangeRatio($service)) {
                $changed[] = $service;
            }
        }

        return $changed;
    }

    /**
     * Nombres de categoría indexados por id, en una sola consulta.
     *
     * @return array<string, string>
     */
    private function categoryNames(): array
    {
        if (!$this->tenantContext->hasOrganization()) {
            return [];
        }

        $names = [];

        foreach ($this->categories->findVisibleForOrganization($this->tenantContext->requireOrganizationId()) as $category) {
            $names[$category->getId()->toRfc4122()] = $category->getName();
        }

        return $names;
    }
}
