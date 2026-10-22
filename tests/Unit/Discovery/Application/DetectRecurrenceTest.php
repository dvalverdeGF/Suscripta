<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Application;

use App\Discovery\Application\DetectRecurrence;
use App\Discovery\Application\Recurrence\IntervalRecurrenceDetector;
use App\Documents\Domain\Entity\Invoice;
use App\Documents\Domain\Enum\InvoiceSource;
use App\Documents\Domain\Enum\InvoiceStatus;
use App\Documents\Domain\Repository\InvoiceRepositoryInterface;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Aplicar la periodicidad detectada al servicio.
 *
 * La regla de oro: **la detección nunca pisa lo que el usuario declaró**. Si el
 * usuario dijo "trimestral" y el historial dice "mensual", se actualiza la
 * fecha de cobro pero la periodicidad sigue siendo la del usuario, y el
 * sistema deja constancia del conflicto en la línea de tiempo.
 */
#[CoversClass(DetectRecurrence::class)]
final class DetectRecurrenceTest extends TestCase
{
    private const NOW = '2026-04-02 10:00:00';

    private InvoiceRepositoryInterface&MockObject $invoices;
    private ServiceRepositoryInterface&MockObject $services;

    protected function setUp(): void
    {
        $this->invoices = $this->createMock(InvoiceRepositoryInterface::class);
        $this->services = $this->createMock(ServiceRepositoryInterface::class);
    }

    public function testItAdoptsTheDetectedPeriodWhenTheServiceDoesNotDeclareOne(): void
    {
        $service = $this->service(BillingPeriod::UNKNOWN);
        $this->givenService($service);
        $this->givenInvoices($this->monthlyInvoices($service));

        $result = ($this->detectRecurrence())($service->getId());

        self::assertTrue($result->isConfident());
        self::assertSame(BillingPeriod::MONTHLY, $service->getBillingPeriod());
        self::assertNotNull($service->getNextChargeAt());
        self::assertSame('2026-04-15', $service->getNextChargeAt()->format('Y-m-d'));
    }

    public function testItRecordsTheDetectionInTheServiceTimeline(): void
    {
        $service = $this->service(BillingPeriod::UNKNOWN);
        $this->givenService($service);
        $this->givenInvoices($this->monthlyInvoices($service));

        ($this->detectRecurrence())($service->getId());

        $events = $service->getEvents()->toArray();
        self::assertCount(1, $events);
        self::assertSame(ServiceEventType::RECURRENCE_DETECTED, $events[0]->getType());
        self::assertTrue($events[0]->getData()['adopted']);
        self::assertSame('monthly', $events[0]->getData()['period']);
    }

    public function testItNeverDowngradesAPeriodTheUserDeclared(): void
    {
        $service = $this->service(BillingPeriod::QUARTERLY);
        $this->givenService($service);
        $this->givenInvoices($this->monthlyInvoices($service));

        ($this->detectRecurrence())($service->getId());

        // El usuario dijo trimestral: el sistema avisa, no reescribe.
        self::assertSame(BillingPeriod::QUARTERLY, $service->getBillingPeriod());
        // La fecha sí se actualiza, porque el cobro real sí tiene fecha.
        self::assertSame('2026-04-15', $service->getNextChargeAt()?->format('Y-m-d'));
    }

    public function testItIgnoresFailedAndRefundedInvoices(): void
    {
        $service = $this->service(BillingPeriod::UNKNOWN);
        $this->givenService($service);

        $paid = $this->invoice($service, '2026-01-15', InvoiceStatus::PAID);
        $failed = $this->invoice($service, '2026-02-15', InvoiceStatus::FAILED);
        $refunded = $this->invoice($service, '2026-03-15', InvoiceStatus::REFUNDED);

        $this->givenInvoices([$paid, $failed, $refunded]);

        $result = ($this->detectRecurrence())($service->getId());

        // Un solo cobro real: no hay patrón.
        self::assertFalse($result->isConfident());
        self::assertSame(BillingPeriod::UNKNOWN, $service->getBillingPeriod());
        self::assertNull($service->getNextChargeAt());
    }

    public function testAnIrregularHistoryLeavesTheServiceUntouched(): void
    {
        $service = $this->service(BillingPeriod::UNKNOWN);
        $service->setNextChargeAt(new DateTimeImmutable('2026-07-01'));
        $this->givenService($service);
        $this->givenInvoices([
            $this->invoice($service, '2026-01-10'),
            $this->invoice($service, '2026-02-05'),
            $this->invoice($service, '2026-05-20'),
        ]);

        $result = ($this->detectRecurrence())($service->getId());

        self::assertFalse($result->isConfident());
        self::assertSame(BillingPeriod::UNKNOWN, $service->getBillingPeriod());
        self::assertSame('2026-07-01', $service->getNextChargeAt()?->format('Y-m-d'));
        self::assertCount(0, $service->getEvents()->toArray());
    }

    public function testItFailsWhenTheServiceDoesNotExist(): void
    {
        $this->services->method('find')->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El servicio no existe.');

        ($this->detectRecurrence())(Uuid::v7());
    }

    public function testItDoesNotTouchTheServiceWhenTheDetectionIsNotConfident(): void
    {
        $service = $this->service(BillingPeriod::UNKNOWN);
        $this->givenService($service);
        $this->givenInvoices([$this->invoice($service, '2026-01-15')]);

        $this->services->expects(self::never())->method('save');

        ($this->detectRecurrence())($service->getId());
    }

    private function detectRecurrence(): DetectRecurrence
    {
        return new DetectRecurrence(
            new IntervalRecurrenceDetector(),
            $this->invoices,
            $this->services,
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );
    }

    private function givenService(Service $service): void
    {
        $this->services->method('find')->willReturn($service);
    }

    /**
     * @param list<Invoice> $invoices
     */
    private function givenInvoices(array $invoices): void
    {
        $this->invoices->method('findForService')->willReturn($invoices);
    }

    private function service(BillingPeriod $period): Service
    {
        return new Service(
            Uuid::v7(),
            'Netflix',
            Currency::EUR,
            $period,
        );
    }

    /**
     * @return list<Invoice>
     */
    private function monthlyInvoices(Service $service): array
    {
        return [
            $this->invoice($service, '2026-01-15'),
            $this->invoice($service, '2026-02-15'),
            $this->invoice($service, '2026-03-15'),
        ];
    }

    private function invoice(Service $service, string $issuedAt, InvoiceStatus $status = InvoiceStatus::PAID): Invoice
    {
        $invoice = new Invoice(
            $service->getOrganizationId(),
            new DateTimeImmutable($issuedAt),
            Money::of(1290, Currency::EUR),
            InvoiceSource::MANUAL,
        );
        $invoice->attachToService($service->getId());

        if (InvoiceStatus::PAID === $status) {
            $invoice->markPaid(new DateTimeImmutable($issuedAt));
        } elseif (InvoiceStatus::FAILED === $status) {
            $invoice->markFailed();
        } else {
            $invoice->markRefunded();
        }

        return $invoice;
    }
}
