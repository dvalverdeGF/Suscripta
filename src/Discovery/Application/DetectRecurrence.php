<?php

declare(strict_types=1);

namespace App\Discovery\Application;

use App\Discovery\Domain\Recurrence\RecurrenceDetectorInterface;
use App\Discovery\Domain\Recurrence\RecurrenceResult;
use App\Discovery\Domain\Recurrence\RecurrenceSample;
use App\Documents\Domain\Entity\Invoice;
use App\Documents\Domain\Enum\InvoiceStatus;
use App\Documents\Domain\Repository\InvoiceRepositoryInterface;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use Symfony\Component\Uid\Uuid;

/**
 * Recalcula la periodicidad y el próximo cobro de un servicio a partir de su
 * historial real de facturas (ARCHITECTURE.md §9, `RecurrenceDetectorInterface`).
 *
 * Es la parte "aplicadora" del detector: el detector dice qué ha deducido y esta
 * clase decide si eso se escribe en el servicio.
 *
 * Reglas:
 *
 *  - Si el patrón **no** es regular no se toca `nextChargeAt`: un cobro irregular
 *    no admite fecha de cobro automática (ARCHITECTURE.md §14).
 *  - Si el servicio no declara periodicidad (`UNKNOWN`) y la detección es fiable,
 *    se adopta la periodicidad deducida.
 *  - La detección nunca baja la confianza de lo que el usuario ya declaró: si el
 *    servicio dice "mensual" y el historial dice "trimestral", gana el usuario y
 *    el sistema lo señala, no lo sobrescribe.
 */
final readonly class DetectRecurrence
{
    public function __construct(
        private RecurrenceDetectorInterface $detector,
        private InvoiceRepositoryInterface $invoices,
        private ServiceRepositoryInterface $services,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvalidArgumentException si el servicio no existe
     */
    public function __invoke(Uuid $serviceId): RecurrenceResult
    {
        $service = $this->services->find($serviceId);

        if (null === $service) {
            throw new InvalidArgumentException('El servicio no existe.');
        }

        $result = $this->detector->detect($this->samples($serviceId), $this->clock->now());

        if ($result->isConfident() && null !== $result->nextChargeAt) {
            $this->apply($service, $result);
        }

        return $result;
    }

    /**
     * Aplica la detección al servicio.
     *
     * Sólo se llama cuando la detección es fiable: un cobro irregular deja el
     * servicio como estaba.
     */
    private function apply(Service $service, RecurrenceResult $result): void
    {
        $detected = $result->period;
        $declared = $service->getBillingPeriod();
        $previousNextChargeAt = $service->getNextChargeAt();
        $periodAdopted = false;

        if (null !== $detected && BillingPeriod::UNKNOWN === $declared) {
            $service->changeBillingPeriod($detected);
            $periodAdopted = true;
        }

        $service->setNextChargeAt($result->nextChargeAt);
        $this->services->save($service);

        if ($periodAdopted || $previousNextChargeAt !== $result->nextChargeAt) {
            $this->recordEvent($service, $result, $periodAdopted);
        }
    }

    /**
     * Deja constancia en la línea de tiempo del servicio.
     *
     * Sin esto el usuario no distinguiría "el sistema detectó la periodicidad" de
     * "el sistema se la inventó".
     */
    private function recordEvent(Service $service, RecurrenceResult $result, bool $periodAdopted): void
    {
        $service->recordEvent(ServiceEventType::RECURRENCE_DETECTED, null, [
            'period' => $result->period?->value,
            'confidence' => $result->confidence,
            'nextChargeAt' => $result->nextChargeAt?->format('Y-m-d'),
            'adopted' => $periodAdopted,
        ]);

        $this->services->save($service);
    }

    /**
     * Historial de cobros del servicio.
     *
     * Las facturas fallidas o devueltas no son cobros: contarlas falsearía el
     * patrón (una factura fallida y su reemisión se leen como dos cobros
     * separados por un día).
     *
     * @return list<RecurrenceSample>
     */
    private function samples(Uuid $serviceId): array
    {
        $samples = [];

        foreach ($this->invoices->findForService($serviceId) as $invoice) {
            if (!$this->countsAsCharge($invoice)) {
                continue;
            }

            $samples[] = new RecurrenceSample(
                chargedAt: $invoice->getPaidAt() ?? $invoice->getIssuedAt(),
                amountMinor: $invoice->getTotalAmountMinor(),
                currency: $invoice->getCurrency()->value,
            );
        }

        return $samples;
    }

    private function countsAsCharge(Invoice $invoice): bool
    {
        return InvoiceStatus::FAILED !== $invoice->getStatus()
            && InvoiceStatus::REFUNDED !== $invoice->getStatus();
    }
}
