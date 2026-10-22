<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Knowledge;

use App\Documents\Domain\Enum\DocumentType;
use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeSource;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * La fila de conocimiento del buzón (ARCHITECTURE.md §13.12).
 *
 * Aquí se prueba la regla que sostiene todo el aprendizaje: **lo deducido solo
 * rellena huecos y lo afirmado por el usuario pisa**. Es la diferencia entre
 * «el sistema cree» y «el usuario ha dicho», y es lo que evita que una
 * deducción equivocada contamine el buzón para siempre.
 */
#[CoversClass(MailboxKnowledgeEntry::class)]
final class MailboxKnowledgeEntryTest extends TestCase
{
    private const NOW = '2026-10-05 10:00:00';

    private Uuid $accountId;
    private Uuid $organizationId;
    private Uuid $providerId;

    protected function setUp(): void
    {
        $this->accountId = Uuid::v7();
        $this->organizationId = Uuid::v7();
        $this->providerId = Uuid::v7();
    }

    public function testItNormalizesSendersToLowercase(): void
    {
        $entry = $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'Facturacion@OVH.com');

        self::assertSame('facturacion@ovh.com', $entry->getValue());
    }

    public function testItKeepsTheCaseOfPatterns(): void
    {
        $entry = $this->entry(MailboxKnowledgeType::SUBJECT_PATTERN, '/^Factura \{n\}$/u');

        self::assertSame('/^Factura \{n\}$/u', $entry->getValue());
    }

    public function testItRejectsAnEmptyValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->entry(MailboxKnowledgeType::SENDER_MAPPING, '   ');
    }

    public function testTheDefaultConfidenceDependsOnTheSource(): void
    {
        $observed = new MailboxKnowledgeEntry(
            $this->accountId,
            $this->organizationId,
            MailboxKnowledgeType::SENDER_MAPPING,
            'a@b.com',
            source: MailboxKnowledgeSource::OBSERVED,
        );

        $user = new MailboxKnowledgeEntry(
            $this->accountId,
            $this->organizationId,
            MailboxKnowledgeType::SENDER_MAPPING,
            'c@d.com',
            source: MailboxKnowledgeSource::USER,
        );

        self::assertSame(60, $observed->getConfidence());
        self::assertSame(100, $user->getConfidence());
    }

    public function testAnObservedLearningOnlyFillsTheGaps(): void
    {
        $entry = $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'a@b.com');
        $entry->learn(
            providerId: $this->providerId,
            documentType: DocumentType::INVOICE,
            billingPeriod: BillingPeriod::MONTHLY,
            confidence: 80,
            authoritative: false,
            at: new DateTimeImmutable(self::NOW),
        );

        $entry->learn(
            providerId: null,
            documentType: DocumentType::RECEIPT,
            billingPeriod: BillingPeriod::ANNUAL,
            confidence: 60,
            authoritative: false,
            at: new DateTimeImmutable(self::NOW),
        );

        self::assertSame(DocumentType::INVOICE, $entry->getDocumentType());
        self::assertSame(BillingPeriod::MONTHLY, $entry->getBillingPeriod());
        self::assertSame($this->providerId, $entry->getProviderId());
    }

    public function testAnAuthoritativeLearningReplacesWhatWasObserved(): void
    {
        $entry = $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'a@b.com');
        $entry->learn(
            providerId: $this->providerId,
            documentType: DocumentType::INVOICE,
            billingPeriod: BillingPeriod::MONTHLY,
            confidence: 60,
            authoritative: false,
            at: new DateTimeImmutable(self::NOW),
        );

        $entry->learn(
            providerId: $this->providerId,
            documentType: DocumentType::RECEIPT,
            billingPeriod: BillingPeriod::ANNUAL,
            confidence: 90,
            authoritative: true,
            at: new DateTimeImmutable(self::NOW),
        );

        self::assertSame(DocumentType::RECEIPT, $entry->getDocumentType());
        self::assertSame(BillingPeriod::ANNUAL, $entry->getBillingPeriod());
        self::assertSame(MailboxKnowledgeSource::USER, $entry->getSource());
    }

    public function testTheConfidenceNeverGoesDownOnItsOwn(): void
    {
        $entry = $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'a@b.com');
        $entry->reinforce(90, new DateTimeImmutable(self::NOW));
        $entry->reinforce(10, new DateTimeImmutable(self::NOW));

        self::assertSame(90, $entry->getConfidence());
    }

    public function testAnIgnoredSenderOnlyExcludesFromTheThreshold(): void
    {
        $entry = $this->entry(MailboxKnowledgeType::IGNORED_SENDER, 'spam@b.com', confidence: 30);
        self::assertFalse($entry->excludes());

        $entry->reinforce(60, new DateTimeImmutable(self::NOW));

        self::assertTrue($entry->excludes());
    }

    public function testANonExclusionTypeNeverExcludes(): void
    {
        $entry = $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'a@b.com');
        $entry->reinforce(100, new DateTimeImmutable(self::NOW));

        self::assertFalse($entry->excludes());
    }

    public function testRecordingAHitCountsAndStampsTheMoment(): void
    {
        $entry = $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'a@b.com');

        self::assertSame(0, $entry->getHitCount());
        self::assertNull($entry->getLastUsedAt());

        $entry->recordHit(new DateTimeImmutable(self::NOW));

        self::assertSame(1, $entry->getHitCount());
        self::assertNotNull($entry->getLastUsedAt());
        self::assertSame('2026-10-05', $entry->getLastUsedAt()->format('Y-m-d'));
    }

    public function testEditingReplacesEveryField(): void
    {
        $entry = $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'a@b.com');

        $entry->edit(
            value: 'Facturacion@OVH.com',
            providerId: $this->providerId,
            documentType: DocumentType::CONTRACT,
            billingPeriod: BillingPeriod::QUARTERLY,
            confidence: 120,
            at: new DateTimeImmutable(self::NOW),
        );

        self::assertSame('facturacion@ovh.com', $entry->getValue());
        self::assertSame($this->providerId, $entry->getProviderId());
        self::assertSame(DocumentType::CONTRACT, $entry->getDocumentType());
        self::assertSame(BillingPeriod::QUARTERLY, $entry->getBillingPeriod());
        self::assertSame(100, $entry->getConfidence());
    }

    public function testPromotionIsStamped(): void
    {
        $entry = $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'a@b.com');

        self::assertFalse($entry->isPromoted());

        $entry->markPromoted(new DateTimeImmutable(self::NOW));

        self::assertTrue($entry->isPromoted());
        self::assertNotNull($entry->getPromotedAt());
    }

    private function entry(MailboxKnowledgeType $type, string $value, ?int $confidence = null): MailboxKnowledgeEntry
    {
        return new MailboxKnowledgeEntry(
            emailAccountId: $this->accountId,
            organizationId: $this->organizationId,
            type: $type,
            value: $value,
            confidence: $confidence,
            now: new DateTimeImmutable(self::NOW),
        );
    }
}
