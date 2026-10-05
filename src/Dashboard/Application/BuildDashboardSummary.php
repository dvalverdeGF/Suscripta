<?php

declare(strict_types=1);

namespace App\Dashboard\Application;

use App\Dashboard\Application\Dto\DashboardSummary;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Services\Domain\Service\ServiceCostCalculator;
use App\Shared\Application\Clock;

use function count;

use DateTimeImmutable;

/**
 * Construye el resumen del panel.
 *
 * El panel es la respuesta a la promesa del producto: «conecta tu correo y
 * descubre automáticamente todo lo que estás pagando». Por eso lo primero que
 * se calcula es el coste, y lo segundo lo que está pendiente de decidir: un
 * descubrimiento sin revisar es valor que el usuario todavía no ha visto.
 */
final readonly class BuildDashboardSummary
{
    private const UPCOMING_WINDOW_DAYS = 30;
    private const UPCOMING_LIMIT = 5;
    private const PENDING_LIMIT = 5;
    private const PRICE_CHANGE_WINDOW_DAYS = 90;

    public function __construct(
        private ServiceRepositoryInterface $services,
        private DiscoveryRepositoryInterface $discoveries,
        private EmailAccountRepositoryInterface $accounts,
        private EmailMessageRepositoryInterface $messages,
        private ServiceCostCalculator $costs,
        private Clock $clock,
    ) {
    }

    public function __invoke(): DashboardSummary
    {
        $now = $this->clock->now();
        $services = $this->services->findForOrganization();

        $accounts = $this->accounts->findForOrganization();
        $indexedMessages = 0;

        foreach ($accounts as $account) {
            $indexedMessages += $this->messages->countForAccount($account->getId());
        }

        return new DashboardSummary(
            activeServices: count(array_filter($services, static fn (Service $service): bool => $service->getStatus()->countsTowardsRecurringCost())),
            totalServices: count($services),
            monthlyByCurrency: $this->costs->totalMonthlyByCurrency($services),
            annualByCurrency: $this->costs->totalAnnualByCurrency($services),
            upcomingCharges: $this->services->findUpcomingCharges($now->modify('+'.self::UPCOMING_WINDOW_DAYS.' days'), self::UPCOMING_LIMIT),
            upcomingRenewals: $this->services->findUpcomingRenewals($now->modify('+'.self::UPCOMING_WINDOW_DAYS.' days'), self::UPCOMING_LIMIT),
            pendingDiscoveries: $this->discoveries->findPending(self::PENDING_LIMIT),
            recentPriceChanges: $this->recentPriceChanges($services, $now),
            connectedMailboxes: count($accounts),
            indexedMessages: $indexedMessages,
        );
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
}
