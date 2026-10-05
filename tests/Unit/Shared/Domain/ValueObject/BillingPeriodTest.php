<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

final class BillingPeriodTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string|null}>
     */
    public static function dayDistances(): iterable
    {
        yield 'semanal' => [7, 'weekly'];
        yield 'mensual' => [30, 'monthly'];
        yield 'mensual con mes de 31 días' => [31, 'monthly'];
        yield 'mensual con febrero' => [28, 'monthly'];
        yield 'bimestral' => [61, 'bimonthly'];
        yield 'trimestral' => [91, 'quarterly'];
        yield 'semestral' => [182, 'semiannual'];
        yield 'anual' => [365, 'annual'];
        yield 'anual bisiesto' => [366, 'annual'];
        yield 'bienal' => [730, 'biennial'];
        yield 'trienal' => [1095, 'triennial'];
        yield 'distancia absurda' => [17, null];
        yield 'cero' => [0, null];
        yield 'negativo' => [-5, null];
    }

    #[DataProvider('dayDistances')]
    public function testFromDayDistance(int $days, ?string $expected): void
    {
        self::assertSame($expected, BillingPeriod::fromDayDistance($days)?->value);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function labels(): iterable
    {
        yield 'inglés mensual' => ['1 month', 'monthly'];
        yield 'inglés anual' => ['yearly', 'annual'];
        yield 'español mensual' => ['mensual', 'monthly'];
        yield 'español anual' => ['Anual', 'annual'];
        yield 'español trimestral' => ['trimestral', 'quarterly'];
        yield 'latín annum' => ['per annum', 'annual'];
        yield 'pago único' => ['one-time', 'one_time'];
        yield 'vacío' => ['', null];
        yield 'desconocido' => ['cuando toque', null];
    }

    #[DataProvider('labels')]
    public function testFromLabel(string $label, ?string $expected): void
    {
        self::assertSame($expected, BillingPeriod::fromLabel($label)?->value);
    }

    public function testTryFromLabelHandlesNull(): void
    {
        self::assertNull(BillingPeriod::tryFromLabel(null));
        self::assertSame(BillingPeriod::MONTHLY, BillingPeriod::tryFromLabel('monthly'));
    }

    public function testFromMonths(): void
    {
        self::assertSame(BillingPeriod::QUARTERLY, BillingPeriod::fromMonths(3));
        self::assertSame(BillingPeriod::ANNUAL, BillingPeriod::fromMonths(12));
    }

    public function testFromMonthsRejectsUnsupportedValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BillingPeriod::fromMonths(5);
    }

    public function testRecurrence(): void
    {
        self::assertTrue(BillingPeriod::MONTHLY->isRecurring());
        self::assertTrue(BillingPeriod::CUSTOM->isRecurring());
        self::assertFalse(BillingPeriod::ONE_TIME->isRecurring());
        self::assertFalse(BillingPeriod::UNKNOWN->isRecurring());
    }

    public function testOccurrencesPerYear(): void
    {
        self::assertSame(12.0, BillingPeriod::MONTHLY->occurrencesPerYear());
        self::assertSame(1.0, BillingPeriod::ANNUAL->occurrencesPerYear());
        self::assertSame(0.5, BillingPeriod::BIENNIAL->occurrencesPerYear());
        self::assertNull(BillingPeriod::ONE_TIME->occurrencesPerYear());
        self::assertNull(BillingPeriod::CUSTOM->occurrencesPerYear());
    }

    public function testMonthsAndDaysAreConsistent(): void
    {
        foreach (BillingPeriod::cases() as $period) {
            $months = $period->months();
            $days = $period->days();

            if (null === $months || null === $days) {
                continue;
            }

            // La duración en días debe quedar dentro del ±15 % de la nominal.
            self::assertEqualsWithDelta(
                $months * 30.44,
                $days,
                $months * 30.44 * 0.15,
                sprintf('Periodicidad %s incoherente.', $period->value),
            );
        }
    }
}
