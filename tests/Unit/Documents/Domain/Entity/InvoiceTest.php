<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documents\Domain\Entity;

use App\Documents\Domain\Entity\Invoice;
use App\Documents\Domain\Enum\InvoiceSource;
use App\Documents\Domain\Enum\InvoiceStatus;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

use function mb_strlen;

use PHPUnit\Framework\TestCase;

use function str_repeat;

use Symfony\Component\Uid\Uuid;

/**
 * La factura es el hecho económico. Su estado y su fecha de cobro no pueden
 * contradecirse: si `paidAt` está relleno, el estado tiene que ser `PAID`, o el
 * panel mostraría un cobro que dice no haberse producido.
 */
final class InvoiceTest extends TestCase
{
    public function testItStartsUnknownAndUnpaid(): void
    {
        $invoice = $this->invoice();

        self::assertSame(InvoiceStatus::UNKNOWN, $invoice->getStatus());
        self::assertNull($invoice->getPaidAt());
        self::assertNull($invoice->getServiceId());
        self::assertNull($invoice->getDocumentId());
        self::assertNull($invoice->getNumber());
    }

    public function testItKeepsTheAmountAsMinorUnits(): void
    {
        $invoice = $this->invoice(total: Money::fromDecimalString('29,90', Currency::EUR));

        self::assertSame(2990, $invoice->getTotalAmountMinor());
        self::assertSame(Currency::EUR, $invoice->getCurrency());
        self::assertSame('29,90 €', $invoice->getTotal()->format());
    }

    public function testMarkingItPaidSetsBothTheDateAndTheStatus(): void
    {
        $invoice = $this->invoice();
        $paidAt = new DateTimeImmutable('2026-10-03 12:00:00');

        $invoice->markPaid($paidAt);

        self::assertSame(InvoiceStatus::PAID, $invoice->getStatus());
        self::assertSame($paidAt->format('c'), $invoice->getPaidAt()?->format('c'));
        self::assertTrue($invoice->getStatus()->confirmsPayment());
    }

    public function testMarkingItFailedClearsThePaymentDate(): void
    {
        $invoice = $this->invoice();
        $invoice->markPaid(new DateTimeImmutable('2026-10-03'));

        $invoice->markFailed();

        self::assertSame(InvoiceStatus::FAILED, $invoice->getStatus());
        self::assertNull($invoice->getPaidAt());
    }

    public function testMarkingItRefundedKeepsThePaymentDate(): void
    {
        $invoice = $this->invoice();
        $invoice->markPaid(new DateTimeImmutable('2026-10-03'));

        $invoice->markRefunded();

        self::assertSame(InvoiceStatus::REFUNDED, $invoice->getStatus());
        self::assertNotNull($invoice->getPaidAt());
    }

    public function testItRejectsAPeriodThatEndsBeforeItStarts(): void
    {
        $invoice = $this->invoice();

        $this->expectException(InvalidArgumentException::class);

        $invoice->setBillingPeriod(
            new DateTimeImmutable('2026-10-31'),
            new DateTimeImmutable('2026-10-01'),
        );
    }

    public function testItAcceptsAPeriodWithOnlyOneEnd(): void
    {
        $invoice = $this->invoice();

        $invoice->setBillingPeriod(new DateTimeImmutable('2026-10-01'), null);

        self::assertNotNull($invoice->getPeriodStart());
        self::assertNull($invoice->getPeriodEnd());
    }

    public function testItTruncatesAnOverlongNumber(): void
    {
        $invoice = $this->invoice();

        $invoice->setNumber(str_repeat('9', 200));

        self::assertSame(120, mb_strlen((string) $invoice->getNumber()));
    }

    public function testTheDedupKeyIgnoresTheSourceMessage(): void
    {
        $providerId = Uuid::v7();
        $issuedAt = new DateTimeImmutable('2026-10-03');

        $first = $this->invoice(issuedAt: $issuedAt);
        $first->setProviderId($providerId);
        $first->setNumber('INV-1');

        $second = $this->invoice(issuedAt: $issuedAt);
        $second->setProviderId($providerId);
        $second->setNumber('INV-1');

        self::assertSame($first->buildDedupKey(), $second->buildDedupKey());
    }

    public function testTheDedupKeyChangesWithTheAmount(): void
    {
        $issuedAt = new DateTimeImmutable('2026-10-03');

        $cheap = $this->invoice(issuedAt: $issuedAt, total: Money::fromDecimalString('9,99', Currency::EUR));
        $dear = $this->invoice(issuedAt: $issuedAt, total: Money::fromDecimalString('19,99', Currency::EUR));

        self::assertNotSame($cheap->buildDedupKey(), $dear->buildDedupKey());
    }

    public function testTheDedupKeyChangesWithTheDay(): void
    {
        $first = $this->invoice(issuedAt: new DateTimeImmutable('2026-10-03'));
        $second = $this->invoice(issuedAt: new DateTimeImmutable('2026-11-03'));

        self::assertNotSame($first->buildDedupKey(), $second->buildDedupKey());
    }

    public function testItIsManualByDefault(): void
    {
        self::assertSame(InvoiceSource::MANUAL, $this->invoice()->getSource());
    }

    public function testItRemembersThatItCameFromTheMailbox(): void
    {
        $invoice = new Invoice(
            organizationId: Uuid::v7(),
            issuedAt: new DateTimeImmutable('2026-10-03'),
            total: Money::fromDecimalString('29,90', Currency::EUR),
            source: InvoiceSource::EMAIL_DISCOVERY,
        );

        self::assertSame(InvoiceSource::EMAIL_DISCOVERY, $invoice->getSource());
        self::assertSame('Detectada en tu correo', $invoice->getSource()->label());
    }

    private function invoice(
        ?DateTimeImmutable $issuedAt = null,
        ?Money $total = null,
    ): Invoice {
        return new Invoice(
            organizationId: Uuid::v7(),
            issuedAt: $issuedAt ?? new DateTimeImmutable('2026-10-03'),
            total: $total ?? Money::fromDecimalString('29,90', Currency::EUR),
        );
    }
}
