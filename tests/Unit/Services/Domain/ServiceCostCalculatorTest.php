<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Domain;

use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Service\ServiceCostCalculator;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ServiceCostCalculator::class)]
final class ServiceCostCalculatorTest extends TestCase
{
    private ServiceCostCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ServiceCostCalculator();
    }

    private function service(
        int $amountMinor,
        BillingPeriod $period,
        Currency $currency = Currency::EUR,
        int $intervalCount = 1,
        ServiceStatus $status = ServiceStatus::ACTIVE,
    ): Service {
        $service = new Service(Uuid::v7(), 'Servicio', $currency, $period, status: $status);
        $service->changePrice(Money::of($amountMinor, $currency), new DateTimeImmutable('2026-01-01'));

        if ($intervalCount > 1) {
            $service->changeBillingPeriod($period, $intervalCount);
        }

        return $service;
    }

    #[DataProvider('monthlyEquivalentProvider')]
    public function testMonthlyEquivalent(int $amountMinor, BillingPeriod $period, int $intervalCount, int $expected): void
    {
        $service = $this->service($amountMinor, $period, intervalCount: $intervalCount);

        self::assertSame($expected, $this->calculator->monthlyEquivalent($service)?->amountMinor);
    }

    /**
     * @return iterable<string, array{int, BillingPeriod, int, int}>
     */
    public static function monthlyEquivalentProvider(): iterable
    {
        yield 'mensual' => [2990, BillingPeriod::MONTHLY, 1, 2990];
        yield 'anual' => [12000, BillingPeriod::ANNUAL, 1, 1000];
        yield 'trimestral' => [3000, BillingPeriod::QUARTERLY, 1, 1000];
        yield 'semestral' => [6000, BillingPeriod::SEMIANNUAL, 1, 1000];
        yield 'bienal' => [24000, BillingPeriod::BIENNIAL, 1, 1000];
        yield 'trienal' => [36000, BillingPeriod::TRIENNIAL, 1, 1000];
        yield 'cada dos meses' => [2000, BillingPeriod::MONTHLY, 2, 1000];
        yield 'semanal' => [1000, BillingPeriod::WEEKLY, 1, 4333];
    }

    public function testMonthlyEquivalentIsNullWithoutPrice(): void
    {
        $service = new Service(Uuid::v7(), 'Sin precio', Currency::EUR, BillingPeriod::MONTHLY);

        self::assertNull($this->calculator->monthlyEquivalent($service));
        self::assertNull($this->calculator->annualCost($service));
    }

    public function testMonthlyEquivalentIsNullForOneTimePayments(): void
    {
        $service = $this->service(5000, BillingPeriod::ONE_TIME);

        self::assertNull($this->calculator->monthlyEquivalent($service));
        self::assertNull($this->calculator->annualCost($service));
    }

    public function testAnnualCost(): void
    {
        self::assertSame(35880, $this->calculator->annualCost($this->service(2990, BillingPeriod::MONTHLY))?->amountMinor);
        self::assertSame(12000, $this->calculator->annualCost($this->service(12000, BillingPeriod::ANNUAL))?->amountMinor);
    }

    public function testTotalsSkipPausedAndCancelledServices(): void
    {
        $active = $this->service(1000, BillingPeriod::MONTHLY);
        $paused = $this->service(5000, BillingPeriod::MONTHLY, status: ServiceStatus::PAUSED);
        $cancelled = $this->service(9000, BillingPeriod::MONTHLY, status: ServiceStatus::CANCELLED);

        $totals = $this->calculator->totalMonthlyByCurrency([$active, $paused, $cancelled]);

        self::assertArrayHasKey('EUR', $totals);
        self::assertSame(1000, $totals['EUR']->amountMinor);
    }

    public function testTotalsAreGroupedByCurrency(): void
    {
        $eur = $this->service(1000, BillingPeriod::MONTHLY);
        $usd = $this->service(2000, BillingPeriod::MONTHLY, Currency::USD);

        $totals = $this->calculator->totalMonthlyByCurrency([$eur, $usd]);

        self::assertSame(1000, $totals['EUR']->amountMinor);
        self::assertSame(2000, $totals['USD']->amountMinor);
    }

    public function testTotalsAreEmptyWithoutServices(): void
    {
        self::assertSame([], $this->calculator->totalMonthlyByCurrency([]));
        self::assertSame([], $this->calculator->totalAnnualByCurrency([]));
    }

    public function testPriceChangeRatio(): void
    {
        $service = $this->service(1000, BillingPeriod::MONTHLY);
        $service->changePrice(Money::of(1200, Currency::EUR), new DateTimeImmutable('2026-06-01'));

        self::assertSame(0.2, $this->calculator->priceChangeRatio($service));
    }

    public function testPriceChangeRatioIsNullWithoutHistory(): void
    {
        self::assertNull($this->calculator->priceChangeRatio($this->service(1000, BillingPeriod::MONTHLY)));
    }

    public function testDominantCurrency(): void
    {
        $services = [
            $this->service(1000, BillingPeriod::MONTHLY),
            $this->service(2000, BillingPeriod::MONTHLY),
            $this->service(3000, BillingPeriod::MONTHLY, Currency::USD),
        ];

        self::assertSame(Currency::EUR, $this->calculator->dominantCurrency($services));
    }

    public function testDominantCurrencyIsNullWithoutPrices(): void
    {
        $service = new Service(Uuid::v7(), 'Sin precio', Currency::EUR, BillingPeriod::MONTHLY);

        self::assertNull($this->calculator->dominantCurrency([$service]));
    }
}
