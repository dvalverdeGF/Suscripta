<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Knowledge;

use App\Processing\Application\Knowledge\KnowledgeSettings;
use App\Processing\Application\Knowledge\PruneMailboxKnowledge;
use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * La poda del conocimiento del buzón (ARCHITECTURE.md §13.12).
 *
 * La poda automática solo elimina lo que **nunca se ha usado** y lleva tiempo
 * guardado. Lo que sí se usa no se toca: un patrón que ha servido puede dejar
 * de casar más adelante, y borrarlo por antigüedad tiraría trabajo ya
 * contrastado. Lo que el usuario ya no quiere lo borra el usuario, no la poda.
 */
#[CoversClass(PruneMailboxKnowledge::class)]
final class PruneMailboxKnowledgeTest extends TestCase
{
    private const NOW = '2026-10-05 10:00:00';

    private MailboxKnowledgeRepositoryInterface&MockObject $entries;
    private PruneMailboxKnowledge $pruner;

    protected function setUp(): void
    {
        $this->entries = $this->createMock(MailboxKnowledgeRepositoryInterface::class);

        $this->pruner = new PruneMailboxKnowledge(
            $this->entries,
            KnowledgeSettings::fromArray(['stale_days' => 90, 'max_entries_per_account' => 500]),
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );
    }

    public function testItRemovesTheStaleEntriesAndReturnsHowMany(): void
    {
        $stale = [$this->entry('a@b.com'), $this->entry('c@d.com')];

        $this->entries->method('findStale')->willReturn($stale);
        $this->entries->expects(self::exactly(2))->method('remove');
        $this->entries->expects(self::once())->method('flush');

        self::assertSame(2, ($this->pruner)());
    }

    public function testItDoesNotFlushWhenThereIsNothingToRemove(): void
    {
        $this->entries->method('findStale')->willReturn([]);
        $this->entries->expects(self::never())->method('remove');
        $this->entries->expects(self::never())->method('flush');

        self::assertSame(0, ($this->pruner)());
    }

    public function testTheThresholdIsTheConfiguredNumberOfDays(): void
    {
        $this->entries->expects(self::once())
            ->method('findStale')
            ->willReturnCallback(static function (DateTimeImmutable $olderThan): array {
                self::assertSame('2026-07-07', $olderThan->format('Y-m-d'));

                return [];
            });

        ($this->pruner)();
    }

    public function testTheDefaultSettingsAreNinetyDays(): void
    {
        $settings = KnowledgeSettings::fromArray([]);

        self::assertSame(90, $settings->staleDays);
        self::assertSame(500, $settings->maxEntriesPerAccount);
    }

    private function entry(string $value): MailboxKnowledgeEntry
    {
        return new MailboxKnowledgeEntry(
            emailAccountId: Uuid::v7(),
            organizationId: Uuid::v7(),
            type: MailboxKnowledgeType::SENDER_MAPPING,
            value: $value,
            now: new DateTimeImmutable(self::NOW),
        );
    }
}
