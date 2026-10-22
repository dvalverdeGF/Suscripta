<?php

declare(strict_types=1);

namespace App\Discovery\Application\Change;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Documents\Domain\Repository\InvoiceRepositoryInterface;
use App\Services\Domain\Dto\ServiceView;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use DateTimeImmutable;

use function floor;
use function intdiv;
use function min;
use function sprintf;

/**
 * Detecta los cobros que no llegaron (D-59).
 *
 * Un servicio recurrente tiene una fecha en la que se supone que le toca
 * pagar. Si esa fecha pasa y no llega ninguna factura, algo ha fallado: o el
 * proveedor ha cambiado la forma de facturar, o la tarjeta ha caducado, o el
 * servicio se ha cancelado sin que nadie lo dijera.
 *
 * El sistema **no puede saber** cuál de las tres es, así que no cancela nada:
 * lo señala. Y la señal se apaga sola: en cuanto llega la factura, el
 * detector de periodicidad recalcula el próximo cobro y la fecha esperada
 * deja de estar en el pasado.
 *
 * El margen de gracia absorbe los días que el proveedor se retrasa: sin él,
 * cada factura llegaría un día tarde y el sistema avisaría de un problema
 * inexistente.
 */
final readonly class DetectMissingCharges
{
    /**
     * Fuerza de la señal al detectarse el problema: 60 puntos y uno más cada
     * dos días de retraso, hasta 100.
     */
    private const int BASE_SCORE = 60;

    public function __construct(
        private ServiceViewRepositoryInterface $services,
        private InvoiceRepositoryInterface $invoices,
        private DiscoveryRepositoryInterface $discoveries,
        private ChangeSettings $settings,
        private TenantContext $tenantContext,
        private Clock $clock,
    ) {
    }

    /**
     * @return list<Discovery>
     */
    public function detect(): array
    {
        $now = $this->clock->now();
        $grace = $this->settings->missingChargeGraceDays;

        $candidates = [];

        foreach ($this->services->findAll(ServiceFilters::none()) as $service) {
            if (ServiceStatus::ACTIVE !== $service->status) {
                continue;
            }

            if (!$service->billingPeriod->isRecurring()) {
                continue;
            }

            $expectedAt = $service->nextChargeAt;

            if (null === $expectedAt) {
                continue;
            }

            $overdueDays = (int) floor(($now->getTimestamp() - $expectedAt->getTimestamp()) / 86400);

            if ($overdueDays <= $grace) {
                continue;
            }

            $candidates[] = ['service' => $service, 'expectedAt' => $expectedAt, 'overdueDays' => $overdueDays];
        }

        if ([] === $candidates) {
            return [];
        }

        $lastCharges = $this->invoices->findLastIssuedAtByService();
        $proposals = [];

        foreach ($candidates as $candidate) {
            /** @var ServiceView $service */
            $service = $candidate['service'];
            $expectedAt = $candidate['expectedAt'];
            $overdueDays = (int) $candidate['overdueDays'];

            if ($this->chargeArrived($lastCharges, $service, $expectedAt, $grace)) {
                continue;
            }

            $proposal = $this->propose($service, $expectedAt, $overdueDays, $lastCharges);

            if (null !== $proposal) {
                $proposals[] = $proposal;
            }
        }

        return $proposals;
    }

    /**
     * @param array<string, DateTimeImmutable> $lastCharges
     */
    private function chargeArrived(
        array $lastCharges,
        ServiceView $service,
        DateTimeImmutable $expectedAt,
        int $grace,
    ): bool {
        $key = $service->id->toRfc4122();
        $lastChargeAt = $lastCharges[$key] ?? null;

        if (null === $lastChargeAt) {
            return false;
        }

        // La factura puede emitirse unos días **antes** de la fecha esperada
        // (cobro anticipado) o unos días después (retraso del proveedor). Por
        // eso el margen de gracia se aplica a los dos lados: si el último
        // cobro cae dentro de la ventana, el cobro llegó.
        $windowStart = $expectedAt->modify(sprintf('-%d days', $grace));

        return $lastChargeAt >= $windowStart;
    }

    /**
     * @param array<string, DateTimeImmutable> $lastCharges
     */
    private function propose(
        ServiceView $service,
        DateTimeImmutable $expectedAt,
        int $overdueDays,
        array $lastCharges,
    ): ?Discovery {
        $key = $service->id->toRfc4122();
        $lastChargeAt = $lastCharges[$key] ?? null;

        $proposedData = [
            'serviceId' => $key,
            'expectedAt' => $expectedAt->format('Y-m-d'),
            'serviceName' => $service->name,
            'currency' => $service->currency->value,
            'amountMinor' => $service->currentAmount?->amountMinor,
            'overdueDays' => $overdueDays,
            'lastChargeAt' => $lastChargeAt?->format('Y-m-d'),
        ];

        $dedupKey = Discovery::buildDedupKey(DiscoveryType::MISSING_CHARGE, $proposedData);

        // Una propuesta por cobro esperado: si el usuario ya la ha visto —la
        // haya aceptado o la haya descartado— no se vuelve a preguntar por ese
        // cobro concreto.
        if (null !== $this->discoveries->findByDedupKey($dedupKey)) {
            return null;
        }

        $score = min(100, self::BASE_SCORE + intdiv($overdueDays, 2));

        $reasons = [
            [
                'signal' => 'Cobro esperado sin factura',
                'weight' => $score,
                'detail' => sprintf('Se esperaba el %s y no hay ninguna factura', $expectedAt->format('d/m/Y')),
            ],
        ];

        if (null !== $lastChargeAt) {
            $reasons[] = [
                'signal' => 'Último cobro conocido',
                'weight' => 0,
                'detail' => $lastChargeAt->format('d/m/Y'),
            ];
        }

        $discovery = new Discovery(
            organizationId: $this->tenantContext->requireOrganizationId(),
            type: DiscoveryType::MISSING_CHARGE,
            detectedAt: $this->clock->now(),
            proposedData: $proposedData,
        );

        $discovery->applyMatch($score, $reasons, $service->id);
        $discovery->applyConfidence($score);
        $discovery->markAsInference();

        $this->discoveries->save($discovery);

        return $discovery;
    }
}
