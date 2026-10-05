<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Domain;

use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Enum\ServiceStatus;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(Service::class)]
final class ServiceTest extends TestCase
{
    private function service(
        BillingPeriod $period = BillingPeriod::MONTHLY,
        Currency $currency = Currency::EUR,
    ): Service {
        return new Service(Uuid::v7(), 'OVH VPS', $currency, $period);
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Service(Uuid::v7(), '   ', Currency::EUR, BillingPeriod::MONTHLY);
    }

    public function testStartsActiveAndManual(): void
    {
        $service = $this->service();

        self::assertSame(ServiceStatus::ACTIVE, $service->getStatus());
        self::assertSame(ServiceSource::MANUAL, $service->getSource());
        self::assertNull($service->getCurrentPrice());
        self::assertNull($service->getCurrentAmount());
    }

    public function testChangePriceClosesPreviousRow(): void
    {
        $service = $this->service();

        self::assertTrue($service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-01')));
        self::assertTrue($service->changePrice(Money::of(1200, Currency::EUR), new DateTimeImmutable('2026-06-01')));

        self::assertCount(2, $service->getPrices());

        $current = $service->getCurrentPrice();
        self::assertNotNull($current);
        self::assertSame(1200, $current->getAmountMinor());
        self::assertNull($current->getValidTo());

        $previous = $service->getPrices()->first();
        self::assertNotFalse($previous);
        self::assertSame(1000, $previous->getAmountMinor());
        self::assertSame('2026-06-01', $previous->getValidTo()?->format('Y-m-d'));
    }

    public function testChangePriceToSameAmountIsANoOp(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-01'));

        self::assertFalse($service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-06-01')));
        self::assertCount(1, $service->getPrices());
    }

    public function testChangePriceRejectsCurrencyMismatch(): void
    {
        $service = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->changePrice(Money::of(1000, Currency::USD), new DateTimeImmutable('2026-01-01'));
    }

    public function testCurrentPriceFallsBackToLatestClosedRow(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-01'));
        $service->changePrice(Money::of(1200, Currency::EUR), new DateTimeImmutable('2026-06-01'));

        // Simula datos importados a medias: la fila vigente se cierra sin abrir otra.
        $service->getCurrentPrice()?->close(new DateTimeImmutable('2026-09-01'));

        self::assertSame(1200, $service->getCurrentAmount()?->amountMinor);
    }

    public function testPauseAndResumeAreIdempotent(): void
    {
        $service = $this->service();

        $service->pause();
        $service->pause();
        self::assertSame(ServiceStatus::PAUSED, $service->getStatus());
        self::assertCount(1, $service->getEvents());

        $service->resume();
        $service->resume();
        self::assertSame(ServiceStatus::ACTIVE, $service->getStatus());
        self::assertCount(2, $service->getEvents());
    }

    public function testCancelClearsNextChargeAndKeepsHistory(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-01'));
        $service->setNextChargeAt(new DateTimeImmutable('2026-07-01'));

        $service->cancel(new DateTimeImmutable('2026-06-15'));

        self::assertSame(ServiceStatus::CANCELLED, $service->getStatus());
        self::assertNull($service->getNextChargeAt());
        self::assertSame('2026-06-15', $service->getCancelledAt()?->format('Y-m-d'));
        self::assertCount(1, $service->getPrices(), 'Cancelar no borra el historial de precios.');
    }

    public function testResumeClearsCancellationDate(): void
    {
        $service = $this->service();
        $service->cancel(new DateTimeImmutable('2026-06-15'));
        $service->resume();

        self::assertSame(ServiceStatus::ACTIVE, $service->getStatus());
        self::assertNull($service->getCancelledAt());
    }

    public function testConfirmFromDiscoveryActivatesAndRecordsEvent(): void
    {
        $service = new Service(
            Uuid::v7(),
            'Adobe Creative Cloud',
            Currency::EUR,
            BillingPeriod::MONTHLY,
            ServiceSource::EMAIL_DISCOVERY,
            ServiceStatus::PENDING_REVIEW,
        );

        $service->confirmFromDiscovery();

        self::assertSame(ServiceStatus::ACTIVE, $service->getStatus());
        $firstEvent = $service->getEvents()->first();
        self::assertNotFalse($firstEvent);
        self::assertSame(ServiceEventType::DISCOVERED, $firstEvent->getType());
    }

    public function testNoticeDeadlineIsRenewalMinusNoticePeriod(): void
    {
        $service = $this->service();
        $service->setRenewalAt(new DateTimeImmutable('2026-12-31'));
        $service->setNoticePeriodDays(30);

        self::assertSame('2026-12-01', $service->getNoticeDeadline()?->format('Y-m-d'));
    }

    public function testNoticeDeadlineIsNullWithoutRenewalOrNoticePeriod(): void
    {
        $service = $this->service();
        self::assertNull($service->getNoticeDeadline());

        $service->setRenewalAt(new DateTimeImmutable('2026-12-31'));
        self::assertNull($service->getNoticeDeadline());
    }

    public function testRecalculateNextChargeUsesStartDateWhenNoPreviousCharge(): void
    {
        $service = $this->service();
        $service->setStartedAt(new DateTimeImmutable('2026-01-15'));
        $service->recalculateNextCharge(now: new DateTimeImmutable('2026-01-20'));

        self::assertSame('2026-02-15', $service->getNextChargeAt()?->format('Y-m-d'));
    }

    public function testRecalculateNextChargeIsIdempotent(): void
    {
        $service = $this->service();
        $service->setStartedAt(new DateTimeImmutable('2026-01-15'));
        $now = new DateTimeImmutable('2026-01-20');

        $service->recalculateNextCharge(now: $now);
        $first = $service->getNextChargeAt()?->format('Y-m-d');

        $service->recalculateNextCharge(now: $now);

        self::assertSame($first, $service->getNextChargeAt()?->format('Y-m-d'));
    }

    public function testRecalculateNextChargeSkipsPastDates(): void
    {
        $service = $this->service();
        $service->setStartedAt(new DateTimeImmutable('2026-01-15'));

        $service->recalculateNextCharge(now: new DateTimeImmutable('2026-04-20'));

        self::assertSame('2026-05-15', $service->getNextChargeAt()?->format('Y-m-d'));
    }

    public function testRecalculateNextChargePrefersTheLastKnownCharge(): void
    {
        $service = $this->service();
        $service->setStartedAt(new DateTimeImmutable('2026-01-15'));

        $service->recalculateNextCharge(new DateTimeImmutable('2026-06-10'), new DateTimeImmutable('2026-06-11'));

        self::assertSame('2026-07-10', $service->getNextChargeAt()?->format('Y-m-d'));
    }

    public function testRecalculateNextChargeDoesNothingForOneTimePayments(): void
    {
        $service = $this->service(BillingPeriod::ONE_TIME);
        $service->setStartedAt(new DateTimeImmutable('2026-01-15'));

        self::assertNull($service->getNextChargeAt());
    }

    public function testRecalculateNextChargeDoesNothingWithoutAnyDate(): void
    {
        $service = $this->service();
        $service->recalculateNextCharge();

        self::assertNull($service->getNextChargeAt());
    }

    /**
     * El 31 de enero más un mes es el 28 de febrero, no el 2 de marzo: la
     * aritmética de calendario es lo que espera el usuario de una suscripción.
     */
    #[DataProvider('advanceProvider')]
    public function testAdvanceUsesCalendarArithmetic(
        string $from,
        BillingPeriod $period,
        int $intervalCount,
        string $expected,
    ): void {
        self::assertSame(
            $expected,
            Service::advance(new DateTimeImmutable($from), $period, $intervalCount)->format('Y-m-d'),
        );
    }

    /**
     * @return iterable<string, array{string, BillingPeriod, int, string}>
     */
    public static function advanceProvider(): iterable
    {
        yield 'mensual desde el 31 de enero' => ['2026-01-31', BillingPeriod::MONTHLY, 1, '2026-02-28'];
        yield 'mensual desde el 15' => ['2026-01-15', BillingPeriod::MONTHLY, 1, '2026-02-15'];
        yield 'trimestral' => ['2026-01-15', BillingPeriod::QUARTERLY, 1, '2026-04-15'];
        yield 'anual' => ['2026-01-15', BillingPeriod::ANNUAL, 1, '2027-01-15'];
        yield 'anual en año bisiesto' => ['2024-02-29', BillingPeriod::ANNUAL, 1, '2025-02-28'];
        yield 'cada dos meses' => ['2026-01-15', BillingPeriod::MONTHLY, 2, '2026-03-15'];
        yield 'semanal' => ['2026-01-15', BillingPeriod::WEEKLY, 1, '2026-01-22'];
        yield 'semanal cada dos semanas' => ['2026-01-15', BillingPeriod::WEEKLY, 2, '2026-01-29'];
        yield 'pago único no avanza' => ['2026-01-15', BillingPeriod::ONE_TIME, 1, '2026-01-15'];
    }

    public function testChangeBillingPeriodRecalculatesNextCharge(): void
    {
        $service = $this->service();
        $service->setStartedAt(new DateTimeImmutable('2026-01-15'));
        $service->changeBillingPeriod(BillingPeriod::ANNUAL);
        $service->recalculateNextCharge(now: new DateTimeImmutable('2026-01-20'));

        self::assertSame('2027-01-15', $service->getNextChargeAt()?->format('Y-m-d'));
    }

    public function testChangeBillingPeriodRejectsNonPositiveInterval(): void
    {
        $service = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->changeBillingPeriod(BillingPeriod::MONTHLY, 0);
    }

    public function testSetNoticePeriodRejectsNegativeValue(): void
    {
        $service = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->setNoticePeriodDays(-1);
    }

    public function testRenameRejectsEmptyName(): void
    {
        $service = $this->service();

        $this->expectException(InvalidArgumentException::class);

        $service->rename('  ');
    }

    public function testBlankOptionalStringsBecomeNull(): void
    {
        $service = $this->service();
        $service->setPlanName('   ');
        $service->setNotes('');
        $service->setPaymentMethodLabel('  ');

        self::assertNull($service->getPlanName());
        self::assertNull($service->getNotes());
        self::assertNull($service->getPaymentMethodLabel());
    }
}
