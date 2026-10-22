<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Application\Recurrence;

use App\Discovery\Application\Recurrence\IntervalRecurrenceDetector;
use App\Discovery\Domain\Recurrence\RecurrenceResult;
use App\Discovery\Domain\Recurrence\RecurrenceSample;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El detector de recurrencia decide si un cobro es predecible.
 *
 * La propiedad que más importa para el usuario es negativa: **un cobro irregular
 * no genera fecha de próximo cobro**. Un dashboard que promete un cobro que
 * no llegará enseña al usuario a no fiarse del producto entero.
 *
 * El detector es determinista: mismas muestras, mismo resultado. No hay IA ni
 * estadística bayesiana, solo distancias en días.
 */
#[CoversClass(IntervalRecurrenceDetector::class)]
final class IntervalRecurrenceDetectorTest extends TestCase
{
    private IntervalRecurrenceDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new IntervalRecurrenceDetector();
    }

    public function testItNeedsAtLeastTwoChargesToSayAnything(): void
    {
        $result = $this->detector->detect([
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2026-01-15'), amountMinor: 1000, currency: 'EUR'),
        ]);

        self::assertFalse($result->hasPeriod());
        self::assertFalse($result->isRegular);
        self::assertFalse($result->isConfident());
        self::assertNull($result->nextChargeAt);
        self::assertStringContainsString('al menos dos cobros', $result->explanation);
    }

    public function testItDetectsAMonthlyPattern(): void
    {
        $result = $this->detector->detect($this->monthlySamples(), new DateTimeImmutable('2026-04-02'));

        self::assertSame(BillingPeriod::MONTHLY, $result->period);
        self::assertTrue($result->isRegular);
        self::assertTrue($result->isConfident());
        self::assertGreaterThanOrEqual(RecurrenceResult::CONFIDENCE_THRESHOLD, $result->confidence);
    }

    public function testItComputesTheNextChargeFromTheLastOne(): void
    {
        $result = $this->detector->detect($this->monthlySamples(), new DateTimeImmutable('2026-04-02'));

        self::assertNotNull($result->nextChargeAt);
        self::assertSame('2026-04-15', $result->nextChargeAt->format('Y-m-d'));
    }

    public function testTheNextChargeIsAlwaysInTheFuture(): void
    {
        // Un historial viejo no puede producir un próximo cobro en el pasado.
        $result = $this->detector->detect($this->monthlySamples(), new DateTimeImmutable('2027-01-01'));

        self::assertNotNull($result->nextChargeAt);
        self::assertGreaterThan(new DateTimeImmutable('2027-01-01'), $result->nextChargeAt);
    }

    public function testAMonthlyChargeOnThe31stKeepsItsDay(): void
    {
        $samples = [
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2025-12-31')),
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2026-01-31')),
        ];

        $result = $this->detector->detect($samples, new DateTimeImmutable('2026-02-01'));

        self::assertSame(BillingPeriod::MONTHLY, $result->period);
        self::assertNotNull($result->nextChargeAt);
        // 31 de enero + 1 mes = 28 de febrero, no 2 de marzo.
        self::assertSame('2026-02-28', $result->nextChargeAt->format('Y-m-d'));
    }

    public function testAnIrregularChargeIsReportedAsIrregularAndHasNoNextCharge(): void
    {
        $samples = [
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2026-01-10')),
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2026-02-05')),
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2026-05-20')),
        ];

        $result = $this->detector->detect($samples, new DateTimeImmutable('2026-05-21'));

        self::assertFalse($result->isRegular);
        self::assertFalse($result->isConfident());
        self::assertNull($result->nextChargeAt);
        self::assertStringContainsString('irregular', $result->explanation);
    }

    public function testItReportsTheAmountAndWhetherItChanged(): void
    {
        $samples = [
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2026-01-15'), amountMinor: 1000, currency: 'EUR'),
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2026-02-15'), amountMinor: 1000, currency: 'EUR'),
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2026-03-15'), amountMinor: 1200, currency: 'EUR'),
        ];

        $result = $this->detector->detect($samples, new DateTimeImmutable('2026-03-16'));

        self::assertSame(1200, $result->amountMinor);
        self::assertSame(1000, $result->previousAmountMinor);
        self::assertTrue($result->amountChanged);
        self::assertEqualsWithDelta(0.2, $result->amountChangeRatio() ?? 0.0, 0.0001);
    }

    public function testItReportsTheIntervalsItUsed(): void
    {
        $result = $this->detector->detect($this->monthlySamples(), new DateTimeImmutable('2026-04-02'));

        self::assertSame([31, 28], $result->intervalsDays);
    }

    public function testTwoChargesOnTheSameDayAreNotAnInterval(): void
    {
        $samples = [
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2026-01-15')),
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2026-01-15')),
        ];

        $result = $this->detector->detect($samples, new DateTimeImmutable('2026-01-16'));

        self::assertSame([], $result->intervalsDays);
        self::assertFalse($result->isRegular);
        self::assertStringContainsString('misma fecha', $result->explanation);
    }

    public function testAQuarterlyPatternIsDetected(): void
    {
        $samples = [
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2025-01-10')),
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2025-04-10')),
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2025-07-10')),
        ];

        $result = $this->detector->detect($samples, new DateTimeImmutable('2025-07-11'));

        self::assertSame(BillingPeriod::QUARTERLY, $result->period);
        self::assertTrue($result->isRegular);
        self::assertNotNull($result->nextChargeAt);
        self::assertSame('2025-10-10', $result->nextChargeAt->format('Y-m-d'));
    }

    public function testTheSameSamplesAlwaysGiveTheSameResult(): void
    {
        $samples = $this->monthlySamples();

        $first = $this->detector->detect($samples, new DateTimeImmutable('2026-04-02'));
        $second = $this->detector->detect($samples, new DateTimeImmutable('2026-04-02'));

        self::assertSame($first->period, $second->period);
        self::assertSame($first->confidence, $second->confidence);
        self::assertSame(
            $first->nextChargeAt?->format('Y-m-d'),
            $second->nextChargeAt?->format('Y-m-d'),
        );
    }

    /**
     * @return list<RecurrenceSample>
     */
    private function monthlySamples(): array
    {
        return [
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2026-01-15'), amountMinor: 2990, currency: 'EUR'),
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2026-02-15'), amountMinor: 2990, currency: 'EUR'),
            new RecurrenceSample(chargedAt: new DateTimeImmutable('2026-03-15'), amountMinor: 2990, currency: 'EUR'),
        ];
    }
}
