<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Domain\Service;

use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Service\PriceHistory;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * El historial de precios es la prueba de que el producto no se inventa los
 * números: cada fila es inmutable y la variación se mide contra la fila
 * inmediatamente anterior, no contra la primera.
 */
#[CoversClass(PriceHistory::class)]
final class PriceHistoryTest extends TestCase
{
    private PriceHistory $history;

    protected function setUp(): void
    {
        $this->history = new PriceHistory();
    }

    public function testAServiceWithoutPricesHasAnEmptyHistory(): void
    {
        self::assertSame([], $this->history->entries($this->service()));
        self::assertNull($this->history->totalVariation($this->service()));
        self::assertNull($this->history->totalVariationRatio($this->service()));
        self::assertSame(0, $this->history->changeCount($this->service()));
    }

    public function testTheFirstPriceHasNoVariation(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1199, Currency::EUR), new DateTimeImmutable('2026-01-15'));

        $entries = $this->history->entries($service);

        self::assertCount(1, $entries);
        self::assertNull($entries[0]->variationAbsolute);
        self::assertNull($entries[0]->variationRatio);
        self::assertFalse($entries[0]->hasVariation());
        self::assertTrue($entries[0]->isCurrent);
    }

    public function testEachRowCarriesTheVariationAgainstThePreviousOne(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-15'));
        $service->changePrice(Money::of(1200, Currency::EUR), new DateTimeImmutable('2026-04-01'));
        $service->changePrice(Money::of(1500, Currency::EUR), new DateTimeImmutable('2026-07-01'));

        $entries = $this->history->entries($service);

        self::assertCount(3, $entries);

        // Ordenadas de más antigua a más reciente.
        self::assertSame('2026-01-15', $entries[0]->validFrom->format('Y-m-d'));
        self::assertSame('2026-04-01', $entries[1]->validFrom->format('Y-m-d'));
        self::assertSame('2026-07-01', $entries[2]->validFrom->format('Y-m-d'));

        // +2,00 € (+20 %) y luego +3,00 € (+25 %), no +5,00 € sobre la primera.
        self::assertSame(200, $entries[1]->variationAbsolute?->amountMinor);
        self::assertEqualsWithDelta(0.2, $entries[1]->variationRatio ?? 0.0, 0.0001);
        self::assertSame(300, $entries[2]->variationAbsolute?->amountMinor);
        self::assertEqualsWithDelta(0.25, $entries[2]->variationRatio ?? 0.0, 0.0001);

        self::assertTrue($entries[1]->isIncrease());
        self::assertTrue($entries[2]->isIncrease());
    }

    public function testADecreaseIsReportedAsSuch(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(2000, Currency::EUR), new DateTimeImmutable('2026-01-15'));
        $service->changePrice(Money::of(1500, Currency::EUR), new DateTimeImmutable('2026-04-01'));

        $entries = $this->history->entries($service);

        self::assertSame(-500, $entries[1]->variationAbsolute?->amountMinor);
        self::assertFalse($entries[1]->isIncrease());
        self::assertTrue($entries[1]->hasVariation());
    }

    public function testOnlyTheOpenRowIsMarkedAsCurrent(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-15'));
        $service->changePrice(Money::of(1200, Currency::EUR), new DateTimeImmutable('2026-04-01'));

        $entries = $this->history->entries($service);

        self::assertFalse($entries[0]->isCurrent);
        self::assertNotNull($entries[0]->validTo);
        self::assertTrue($entries[1]->isCurrent);
        self::assertNull($entries[1]->validTo);
    }

    public function testTheTotalVariationGoesFromTheFirstPriceToTheCurrentOne(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-15'));
        $service->changePrice(Money::of(1200, Currency::EUR), new DateTimeImmutable('2026-04-01'));
        $service->changePrice(Money::of(1500, Currency::EUR), new DateTimeImmutable('2026-07-01'));

        self::assertSame(500, $this->history->totalVariation($service)?->amountMinor);
        self::assertEqualsWithDelta(0.5, $this->history->totalVariationRatio($service) ?? 0.0, 0.0001);
        self::assertSame(2, $this->history->changeCount($service));
    }

    public function testASinglePriceHasNoTotalVariation(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-15'));

        // Devolver cero haría creer que el precio nunca ha cambiado.
        self::assertNull($this->history->totalVariation($service));
        self::assertNull($this->history->totalVariationRatio($service));
    }

    public function testTheHistoryKeepsTheSourceAndTheNoteOfEveryRow(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-15'), ServiceSource::MANUAL, 'Alta');
        $service->changePrice(Money::of(1200, Currency::EUR), new DateTimeImmutable('2026-04-01'), ServiceSource::EMAIL_DISCOVERY, 'Subida anual');

        $entries = $this->history->entries($service);

        self::assertSame(ServiceSource::MANUAL, $entries[0]->source);
        self::assertSame('Alta', $entries[0]->note);
        self::assertSame(ServiceSource::EMAIL_DISCOVERY, $entries[1]->source);
        self::assertSame('Subida anual', $entries[1]->note);
    }

    public function testTheHistoryIsImmutable(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-15'));
        $service->changePrice(Money::of(1200, Currency::EUR), new DateTimeImmutable('2026-04-01'));

        $before = $this->history->entries($service);

        // Volver a leer no reescribe nada: la fila antigua sigue cerrada con su
        // importe original.
        $after = $this->history->entries($service);

        self::assertSame(1000, $after[0]->amount->amountMinor);
        self::assertSame($before[0]->validTo?->format('Y-m-d'), $after[0]->validTo?->format('Y-m-d'));
        self::assertSame(1200, $after[1]->amount->amountMinor);
    }

    private function service(): Service
    {
        return new Service(Uuid::v7(), 'OVH VPS', Currency::EUR, BillingPeriod::MONTHLY);
    }
}
