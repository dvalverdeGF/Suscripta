<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Extraction;

use App\Processing\Application\Extraction\AmountParser;
use App\Shared\Domain\ValueObject\Currency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El parser de importes es la pieza que decide cuánto cuesta un servicio, así
 * que su criterio es conservador: prefiere no devolver nada a devolver un
 * importe equivocado.
 */
#[CoversClass(AmountParser::class)]
final class AmountParserTest extends TestCase
{
    private AmountParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AmountParser();
    }

    /**
     * @return iterable<string, array{string, int, Currency}>
     */
    public static function amounts(): iterable
    {
        yield 'europeo con símbolo detrás' => ['Total: 29,90 €', 2990, Currency::EUR];
        yield 'europeo con miles' => ['Importe 1.234,56 €', 123456, Currency::EUR];
        yield 'anglosajón' => ['Total: $1,234.56', 123456, Currency::USD];
        yield 'código ISO delante' => ['EUR 29.90', 2990, Currency::EUR];
        yield 'código ISO detrás' => ['29.90 EUR', 2990, Currency::EUR];
        yield 'libras' => ['£12.00', 1200, Currency::GBP];
        yield 'francos suizos' => ['CHF 9,50', 950, Currency::CHF];
        yield 'espacio duro como separador de miles' => ["1\u{00A0}234,56 €", 123456, Currency::EUR];
        yield 'sin decimales no es importe' => ['Factura 2026', 0, Currency::EUR];
    }

    #[DataProvider('amounts')]
    public function testFindTotal(string $text, int $expectedMinor, Currency $expectedCurrency): void
    {
        $total = $this->parser->findTotal($text);

        if (0 === $expectedMinor) {
            self::assertNull($total, 'Un número sin separador decimal no es un importe.');

            return;
        }

        self::assertNotNull($total);
        self::assertSame($expectedMinor, $total->amountMinor);
        self::assertSame($expectedCurrency, $total->currency);
    }

    public function testEmptyTextYieldsNothing(): void
    {
        self::assertSame([], $this->parser->findAll('   '));
        self::assertNull($this->parser->findTotal(''));
    }

    public function testFindAllKeepsOrderOfAppearance(): void
    {
        $amounts = $this->parser->findAll('Subtotal 10,00 € e IVA 2,10 € y total 12,10 €');

        self::assertCount(3, $amounts);
        self::assertSame(1000, $amounts[0]->amountMinor);
        self::assertSame(210, $amounts[1]->amountMinor);
        self::assertSame(1210, $amounts[2]->amountMinor);
    }

    /**
     * En una factura el total siempre es mayor que las líneas de detalle, y el
     * total es lo que se cobra.
     */
    public function testFindTotalPicksTheLargestAmount(): void
    {
        $total = $this->parser->findTotal('Subtotal 10,00 € e IVA 2,10 € y total 12,10 €');

        self::assertNotNull($total);
        self::assertSame(1210, $total->amountMinor);
    }

    public function testDefaultCurrencyAppliesWhenNoMarker(): void
    {
        $total = $this->parser->findTotal('Total 29,90', Currency::SEK);

        self::assertNotNull($total);
        self::assertSame(Currency::SEK, $total->currency);
    }

    public function testYearIsNotAnAmount(): void
    {
        self::assertNull($this->parser->findTotal('Factura del ejercicio 2026 con referencia 12345'));
    }
}
