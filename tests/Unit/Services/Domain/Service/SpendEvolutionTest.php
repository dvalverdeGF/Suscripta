<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Domain\Service;

use App\Services\Domain\Entity\Service;
use App\Services\Domain\Service\SpendEvolution;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * La evolución del gasto tiene que ser **histórica**: cada mes usa el precio
 * que estaba vigente entonces. Proyectar el precio de hoy hacia atrás daría una
 * línea plana y mentiría justo cuando el usuario acaba de sufrir una subida.
 */
#[CoversClass(SpendEvolution::class)]
final class SpendEvolutionTest extends TestCase
{
    private SpendEvolution $evolution;

    protected function setUp(): void
    {
        $this->evolution = new SpendEvolution();
    }

    public function testItReturnsOnePointPerMonthIncludingBothEnds(): void
    {
        $points = $this->evolution->monthly(
            [],
            new DateTimeImmutable('2026-05-20'),
            new DateTimeImmutable('2026-10-05'),
        );

        self::assertCount(6, $points);
        self::assertSame('2026-05-01', $points[0]->month->format('Y-m-d'));
        self::assertSame('2026-10-01', $points[5]->month->format('Y-m-d'));
    }

    public function testASingleMonthProducesASinglePoint(): void
    {
        $points = $this->evolution->monthly(
            [],
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable('2026-10-31'),
        );

        self::assertCount(1, $points);
    }

    public function testItUsesThePriceThatWasInForceInEachMonth(): void
    {
        $service = $this->service('OVH VPS', BillingPeriod::MONTHLY);
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-15'));
        $service->changePrice(Money::of(1500, Currency::EUR), new DateTimeImmutable('2026-08-01'));

        $points = $this->evolution->monthly(
            [$service],
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-10-01'),
        );

        // Junio y julio a 10,00 €; agosto, septiembre y octubre a 15,00 €.
        self::assertSame(1000, $points[0]->singleTotal()?->amountMinor);
        self::assertSame(1000, $points[1]->singleTotal()?->amountMinor);
        self::assertSame(1500, $points[2]->singleTotal()?->amountMinor);
        self::assertSame(1500, $points[4]->singleTotal()?->amountMinor);
    }

    public function testAServiceThatDidNotExistYetDoesNotCount(): void
    {
        $service = $this->service('Nuevo', BillingPeriod::MONTHLY);
        $service->setStartedAt(new DateTimeImmutable('2026-09-01'));
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-09-01'));

        $points = $this->evolution->monthly(
            [$service],
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-10-01'),
        );

        self::assertSame(0, $points[0]->serviceCount);
        self::assertSame([], $points[0]->byCurrency);
        self::assertSame(1, $points[3]->serviceCount);
        self::assertSame(1000, $points[3]->singleTotal()?->amountMinor);
    }

    public function testACancelledServiceStopsCountingFromTheMonthItWasCancelled(): void
    {
        $service = $this->service('Cancelado', BillingPeriod::MONTHLY);
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-15'));
        $service->cancel(new DateTimeImmutable('2026-08-10'));

        $points = $this->evolution->monthly(
            [$service],
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-10-01'),
        );

        self::assertSame(1, $points[0]->serviceCount);
        self::assertSame(1, $points[2]->serviceCount);
        self::assertSame(0, $points[3]->serviceCount);
        self::assertSame(0, $points[4]->serviceCount);
    }

    public function testItNormalisesTheBillingPeriodToAMonthlyEquivalent(): void
    {
        $annual = $this->service('Dominio .com', BillingPeriod::ANNUAL);
        $annual->changePrice(Money::of(12000, Currency::EUR), new DateTimeImmutable('2026-01-15'));

        $points = $this->evolution->monthly(
            [$annual],
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable('2026-10-01'),
        );

        self::assertSame(1000, $points[0]->singleTotal()?->amountMinor);
    }

    public function testItKeepsTheCurrenciesApart(): void
    {
        $euros = $this->service('OVH VPS', BillingPeriod::MONTHLY);
        $euros->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-15'));

        $dollars = $this->service('GitHub', BillingPeriod::MONTHLY, Currency::USD);
        $dollars->changePrice(Money::of(400, Currency::USD), new DateTimeImmutable('2026-01-15'));

        $points = $this->evolution->monthly(
            [$euros, $dollars],
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable('2026-10-01'),
        );

        self::assertNull($points[0]->singleTotal(), 'Con dos divisas no hay un único total.');
        self::assertSame(1000, $points[0]->byCurrency['EUR']->amountMinor);
        self::assertSame(400, $points[0]->byCurrency['USD']->amountMinor);
    }

    public function testAServiceWithoutPricesDoesNotCount(): void
    {
        $points = $this->evolution->monthly(
            [$this->service('Sin precio', BillingPeriod::MONTHLY)],
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable('2026-10-01'),
        );

        self::assertSame(0, $points[0]->serviceCount);
    }

    public function testANonRecurringServiceNeverCounts(): void
    {
        $service = $this->service('Pago único', BillingPeriod::ONE_TIME);
        $service->changePrice(Money::of(9900, Currency::EUR), new DateTimeImmutable('2026-01-15'));

        $points = $this->evolution->monthly(
            [$service],
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable('2026-10-01'),
        );

        self::assertSame(0, $points[0]->serviceCount);
    }

    private function service(
        string $name,
        BillingPeriod $period,
        Currency $currency = Currency::EUR,
    ): Service {
        return new Service(Uuid::v7(), $name, $currency, $period);
    }
}
