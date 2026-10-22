<?php

declare(strict_types=1);

namespace App\Services\Application\Privacy;

use App\Services\Domain\Entity\Service;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataProviderInterface;

use function count;

use Symfony\Component\Uid\Uuid;

/**
 * Sección «services» de la exportación: el inventario de suscripciones.
 *
 * Una fila por servicio, con su precio actual y sus fechas clave. Es la
 * sección que más se mira: es la que el usuario reconoce como «sus datos».
 *
 * Se exporta también el histórico de precios y los eventos del servicio, en
 * la propia fila, porque el precio de hoy no explica por qué el de marzo era
 * otro.
 */
final readonly class ServicesDataProvider implements OrganizationDataProviderInterface
{
    public function __construct(
        private ServiceRepositoryInterface $services,
    ) {
    }

    public function section(): string
    {
        return 'services';
    }

    public function export(Uuid $organizationId): array
    {
        $services = $this->services->findAllForOrganization($organizationId);

        $rows = [];
        $total = 0;

        foreach ($services as $service) {
            $amount = $service->getCurrentAmount();

            $rows[] = [
                'id' => $service->getId()->toRfc4122(),
                'name' => $service->getName(),
                'plan' => $service->getPlanName(),
                'status' => $service->getStatus()->value,
                'source' => $service->getSource()->value,
                'amount' => $amount?->amountMinor,
                'currency' => $amount?->currency->value,
                'period' => $service->getBillingPeriod()->value,
                'intervalCount' => $service->getBillingIntervalCount(),
                'startedAt' => $service->getStartedAt()?->format('c'),
                'nextChargeAt' => $service->getNextChargeAt()?->format('c'),
                'renewalAt' => $service->getRenewalAt()?->format('c'),
                'commitmentEndAt' => $service->getCommitmentEndAt()?->format('c'),
                'cancelledAt' => $service->getCancelledAt()?->format('c'),
                'autoRenews' => $service->isAutoRenews(),
                'paymentMethod' => $service->getPaymentMethodLabel(),
                'notes' => $service->getNotes(),
                'providerId' => $service->getProviderId()?->toRfc4122(),
                'categoryId' => $service->getCategoryId()?->toRfc4122(),
                'createdAt' => $service->getCreatedAt()->format('c'),
                'prices' => $this->pricesOf($service),
                'events' => $this->eventsOf($service),
            ];

            $total += $amount->amountMinor ?? 0;
        }

        return [
            'meta' => [
                'count' => count($rows),
                'totalAmountMinor' => $total,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pricesOf(Service $service): array
    {
        $prices = [];

        foreach ($service->getPrices() as $price) {
            $prices[] = [
                'amount' => $price->getAmountMinor(),
                'currency' => $price->getCurrency()->value,
                'validFrom' => $price->getValidFrom()->format('c'),
                'validTo' => $price->getValidTo()?->format('c'),
                'note' => $price->getNote(),
            ];
        }

        return $prices;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventsOf(Service $service): array
    {
        $events = [];

        foreach ($service->getEvents() as $event) {
            $events[] = [
                'type' => $event->getType()->value,
                'occurredAt' => $event->getOccurredAt()->format('c'),
                'data' => $event->getData(),
            ];
        }

        return $events;
    }
}
