<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Knowledge;

use App\Processing\Application\Knowledge\RecordSenderRejection;
use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeSource;
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
 * Lo que se aprende de un descarte (ARCHITECTURE.md §13.12, D-53).
 *
 * La regla es deliberadamente conservadora: **un descarte no es una condena**.
 * El usuario puede descartar una factura suelta y seguir queriendo las demás
 * de ese remitente, así que el remitente solo se salta los mensajes a partir del
 * segundo descarte. El usuario siempre puede ajustarlo a mano.
 */
#[CoversClass(RecordSenderRejection::class)]
final class RecordSenderRejectionTest extends TestCase
{
    private const NOW = '2026-10-05 10:00:00';

    private Uuid $accountId;
    private Uuid $organizationId;
    private MailboxKnowledgeRepositoryInterface&MockObject $entries;
    private RecordSenderRejection $rejection;

    protected function setUp(): void
    {
        $this->accountId = Uuid::v7();
        $this->organizationId = Uuid::v7();

        $this->entries = $this->createMock(MailboxKnowledgeRepositoryInterface::class);
        $this->entries->method('findByValue')->willReturn(null);

        $this->rejection = new RecordSenderRejection(
            $this->entries,
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );
    }

    public function testTheFirstDismissalDoesNotExcludeTheSender(): void
    {
        $saved = null;
        $this->entries->method('save')->willReturnCallback(
            static function (MailboxKnowledgeEntry $entry) use (&$saved): void {
                $saved = $entry;
            },
        );

        ($this->rejection)($this->accountId, $this->organizationId, 'Facturacion@OVH.com');

        self::assertInstanceOf(MailboxKnowledgeEntry::class, $saved);
        self::assertSame(MailboxKnowledgeType::IGNORED_SENDER, $saved->getType());
        self::assertSame('facturacion@ovh.com', $saved->getValue());
        self::assertSame(30, $saved->getConfidence());
        self::assertFalse($saved->excludes());
    }

    public function testTheSecondDismissalExcludesTheSender(): void
    {
        $existing = new MailboxKnowledgeEntry(
            emailAccountId: $this->accountId,
            organizationId: $this->organizationId,
            type: MailboxKnowledgeType::IGNORED_SENDER,
            value: 'facturacion@ovh.com',
            source: MailboxKnowledgeSource::OBSERVED,
            confidence: 30,
            now: new DateTimeImmutable(self::NOW),
        );

        $this->entries = $this->createMock(MailboxKnowledgeRepositoryInterface::class);
        $this->entries->method('findByValue')->willReturn($existing);

        $rejection = new RecordSenderRejection(
            $this->entries,
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );

        $rejection($this->accountId, $this->organizationId, 'facturacion@ovh.com');

        self::assertSame(60, $existing->getConfidence());
        self::assertTrue($existing->excludes());
    }

    public function testTheConfidenceNeverPassesOneHundred(): void
    {
        $existing = new MailboxKnowledgeEntry(
            emailAccountId: $this->accountId,
            organizationId: $this->organizationId,
            type: MailboxKnowledgeType::IGNORED_SENDER,
            value: 'facturacion@ovh.com',
            source: MailboxKnowledgeSource::OBSERVED,
            confidence: 90,
            now: new DateTimeImmutable(self::NOW),
        );

        $this->entries = $this->createMock(MailboxKnowledgeRepositoryInterface::class);
        $this->entries->method('findByValue')->willReturn($existing);

        $rejection = new RecordSenderRejection(
            $this->entries,
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );

        $rejection($this->accountId, $this->organizationId, 'facturacion@ovh.com');

        self::assertSame(100, $existing->getConfidence());
    }

    public function testAnEmptySenderIsIgnored(): void
    {
        $this->entries->expects(self::never())->method('save');

        ($this->rejection)($this->accountId, $this->organizationId, '   ');
    }
}
