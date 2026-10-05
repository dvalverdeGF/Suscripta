<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function decimalStrings(): iterable
    {
        yield 'punto decimal' => ['29.90', 2990];
        yield 'coma decimal' => ['29,90', 2990];
        yield 'entero' => ['30', 3000];
        yield 'un decimal' => ['29,9', 2990];
        yield 'miles con punto' => ['1.234,56', 123456];
        yield 'miles con coma' => ['1,234.56', 123456];
        yield 'miles sin decimales' => ['1.234', 123400];
        yield 'con símbolo' => ['29,90 €', 2990];
        yield 'con espacios' => ['  29,90  ', 2990];
        yield 'negativo' => ['-12,50', -1250];
        yield 'cero' => ['0,00', 0];
        // Heurística: tres dígitos tras el separador se leen como separador de miles.
        yield 'tres dígitos tras el separador' => ['29,999', 2999900];
    }

    #[DataProvider('decimalStrings')]
    public function testFromDecimalString(string $input, int $expectedMinor): void
    {
        self::assertSame($expectedMinor, Money::fromDecimalString($input, Currency::EUR)->amountMinor);
    }

    public function testFromDecimalStringRejectsGarbage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimalString('sin importe', Currency::EUR);
    }

    public function testYenHasNoDecimals(): void
    {
        self::assertSame(1200, Money::fromDecimalString('1200', Currency::JPY)->amountMinor);
        self::assertSame(1200, Money::fromDecimalString('1200,00', Currency::JPY)->amountMinor);
    }

    public function testArithmeticKeepsMinorUnits(): void
    {
        $a = Money::fromDecimalString('10,10', Currency::EUR);
        $b = Money::fromDecimalString('0,05', Currency::EUR);

        self::assertSame(1015, $a->add($b)->amountMinor);
        self::assertSame(1005, $a->subtract($b)->amountMinor);
        self::assertSame(2020, $a->multiply(2)->amountMinor);
    }

    public function testArithmeticRejectsMixedCurrencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(100, Currency::EUR)->add(Money::of(100, Currency::USD));
    }

    public function testRelativeDifference(): void
    {
        $before = Money::fromDecimalString('10,00', Currency::EUR);
        $after = Money::fromDecimalString('12,00', Currency::EUR);

        self::assertEqualsWithDelta(0.2, $after->relativeDifferenceTo($before), 1e-9);
        self::assertNull($after->relativeDifferenceTo(Money::zero(Currency::EUR)));
    }

    public function testFormatUsesSpanishConventions(): void
    {
        self::assertSame('1.234,56 €', Money::fromDecimalString('1234,56', Currency::EUR)->format());
        self::assertSame('1.234,56', Money::fromDecimalString('1234,56', Currency::EUR)->format(false));
        self::assertSame('-12,50 €', Money::fromDecimalString('-12,50', Currency::EUR)->format());
        self::assertSame('1.200 ¥', Money::fromDecimalString('1200', Currency::JPY)->format());
    }

    public function testEquality(): void
    {
        self::assertTrue(Money::of(100, Currency::EUR)->equals(Money::of(100, Currency::EUR)));
        self::assertFalse(Money::of(100, Currency::EUR)->equals(Money::of(100, Currency::USD)));
        self::assertTrue(Money::zero(Currency::EUR)->isZero());
        self::assertTrue(Money::of(-1, Currency::EUR)->isNegative());
    }
}
