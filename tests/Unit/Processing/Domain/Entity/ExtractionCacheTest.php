<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Domain\Entity;

use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Processing\Domain\Dto\ExtractedDocument;
use App\Processing\Domain\Entity\ExtractionCache;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ExtractionCache::class)]
final class ExtractionCacheTest extends TestCase
{
    private function document(): ExtractedDocument
    {
        return new ExtractedDocument(
            tier: ExtractionTier::DETERMINISTIC,
            confidence: 0.93,
            amountMinor: 2990,
            currency: 'EUR',
            billingPeriod: BillingPeriod::MONTHLY,
            providerName: 'OVH',
        );
    }

    private function cache(string $hash = 'a1b2c3'): ExtractionCache
    {
        return new ExtractionCache(
            organizationId: Uuid::v7(),
            contentHash: $hash,
            document: $this->document(),
            createdAt: new DateTimeImmutable('2026-10-05 10:00:00'),
        );
    }

    public function testItStoresTheExtractionAsJson(): void
    {
        $cache = $this->cache();

        $document = $cache->getDocument();

        self::assertSame(2990, $document->amountMinor);
        self::assertSame('EUR', $document->currency);
        self::assertSame(BillingPeriod::MONTHLY, $document->billingPeriod);
        self::assertSame('OVH', $document->providerName);
        self::assertSame(ExtractionTier::DETERMINISTIC, $document->tier);
    }

    public function testItStartsWithoutHits(): void
    {
        $cache = $this->cache();

        self::assertSame(0, $cache->getHitCount());
        self::assertSame('2026-10-05', $cache->getLastUsedAt()->format('Y-m-d'));
        self::assertSame('2026-10-05', $cache->getCreatedAt()->format('Y-m-d'));
    }

    public function testRecordingAHitCountsItAndMovesTheLastUse(): void
    {
        $cache = $this->cache();

        $cache->recordHit(new DateTimeImmutable('2026-11-01 09:00:00'));
        $cache->recordHit(new DateTimeImmutable('2026-12-01 09:00:00'));

        self::assertSame(2, $cache->getHitCount());
        self::assertSame('2026-12-01', $cache->getLastUsedAt()->format('Y-m-d'));
        self::assertSame('2026-10-05', $cache->getCreatedAt()->format('Y-m-d'));
    }

    public function testItKeepsTheConfidenceAsHundredths(): void
    {
        $cache = $this->cache();

        self::assertSame(93, $cache->getConfidence());
        self::assertSame(ExtractionTier::DETERMINISTIC, $cache->getTier());
    }

    public function testItBelongsToAnOrganization(): void
    {
        $organizationId = Uuid::v7();
        $cache = new ExtractionCache($organizationId, 'abc', $this->document(), new DateTimeImmutable());

        self::assertSame($organizationId->toRfc4122(), $cache->getOrganizationId()->toRfc4122());
    }

    public function testItRejectsAnEmptyContentHash(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExtractionCache(Uuid::v7(), '', $this->document(), new DateTimeImmutable());
    }

    public function testItTruncatesAnOverlongHashToTheColumnWidth(): void
    {
        $cache = $this->cache(str_repeat('a', 100));

        self::assertSame(64, mb_strlen($cache->getContentHash()));
    }
}
