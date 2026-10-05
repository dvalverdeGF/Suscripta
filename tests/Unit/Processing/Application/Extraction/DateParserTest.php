<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Extraction;

use App\Processing\Application\Extraction\DateParser;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Una fecha de renovación inventada genera un aviso falso, y un aviso falso
 * destruye la confianza en el producto más rápido que un aviso ausente. Por eso
 * el parser descarta en lugar de adivinar.
 */
#[CoversClass(DateParser::class)]
final class DateParserTest extends TestCase
{
    private DateParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DateParser();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dates(): iterable
    {
        yield 'ISO' => ['Emitida el 2026-10-03', '2026-10-03'];
        yield 'europeo con barras' => ['Fecha: 03/10/2026', '2026-10-03'];
        yield 'europeo con puntos' => ['Fecha: 3.10.2026', '2026-10-03'];
        yield 'europeo con guiones' => ['Fecha: 03-10-2026', '2026-10-03'];
        yield 'año de dos dígitos' => ['Fecha: 03/10/26', '2026-10-03'];
        yield 'español' => ['Vence el 3 de octubre de 2026', '2026-10-03'];
        yield 'inglés' => ['Due October 3, 2026', '2026-10-03'];
        yield 'inglés abreviado' => ['Due Oct 3 2026', '2026-10-03'];
    }

    #[DataProvider('dates')]
    public function testFindFirst(string $text, string $expected): void
    {
        $date = $this->parser->findFirst($text);

        self::assertNotNull($date);
        self::assertSame($expected, $date->format('Y-m-d'));
    }

    public function testEmptyTextYieldsNothing(): void
    {
        self::assertSame([], $this->parser->findAll(''));
        self::assertNull($this->parser->findFirst('   '));
    }

    public function testRejectsImpossibleDates(): void
    {
        self::assertNull($this->parser->findFirst('Fecha: 31/02/2026'));
        self::assertNull($this->parser->findFirst('Fecha: 45/13/2026'));
    }

    public function testRejectsDatesOutsidePlausibleRange(): void
    {
        self::assertNull($this->parser->findFirst('Fecha: 03/10/1899'));
        self::assertNull($this->parser->findFirst('Fecha: 03/10/2199'));
    }

    public function testNotBeforeFiltersOlderDates(): void
    {
        $dates = $this->parser->findAll(
            'Contratado el 2020-01-15 y renovado el 2026-10-03',
            new DateTimeImmutable('2025-01-01'),
        );

        self::assertCount(1, $dates);
        self::assertSame('2026-10-03', $dates[0]->format('Y-m-d'));
    }

    public function testFindAllReturnsEveryFormatInOrder(): void
    {
        $dates = $this->parser->findAll('Del 2026-01-01 al 2026-02-01');

        self::assertCount(2, $dates);
        self::assertSame('2026-01-01', $dates[0]->format('Y-m-d'));
        self::assertSame('2026-02-01', $dates[1]->format('Y-m-d'));
    }

    public function testDaysBetweenIsUsedToDeducePeriodicity(): void
    {
        self::assertSame(31, $this->parser->daysBetween(
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-02-01'),
        ));

        self::assertSame(365, $this->parser->daysBetween(
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2027-01-01'),
        ));
    }
}
