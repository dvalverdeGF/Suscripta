<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\DateRange;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DateRangeTest extends TestCase
{
    public function testRejectsInvertedRange(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DateRange(new DateTimeImmutable('2026-02-01'), new DateTimeImmutable('2026-01-01'));
    }

    public function testOpenEndedRangeContainsEverythingAfterStart(): void
    {
        $range = DateRange::startingAt(new DateTimeImmutable('2026-01-01'));

        self::assertTrue($range->isOpenEnded());
        self::assertTrue($range->contains(new DateTimeImmutable('2030-01-01')));
        self::assertFalse($range->contains(new DateTimeImmutable('2025-12-31')));
        self::assertNull($range->days());
    }

    public function testClosedRangeIsHalfOpen(): void
    {
        $range = DateRange::between(
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-02-01'),
        );

        self::assertTrue($range->contains(new DateTimeImmutable('2026-01-01')));
        self::assertFalse($range->contains(new DateTimeImmutable('2026-02-01')));
        self::assertSame(31, $range->days());
    }

    public function testOverlaps(): void
    {
        $january = DateRange::between(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-01'));
        $february = DateRange::between(new DateTimeImmutable('2026-02-01'), new DateTimeImmutable('2026-03-01'));
        $midJanuary = DateRange::between(new DateTimeImmutable('2026-01-15'), new DateTimeImmutable('2026-01-20'));

        self::assertFalse($january->overlaps($february));
        self::assertTrue($january->overlaps($midJanuary));
        self::assertTrue($january->overlaps(DateRange::startingAt(new DateTimeImmutable('2026-01-15'))));
    }
}
