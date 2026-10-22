<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Knowledge;

use App\Documents\Domain\Enum\DocumentType;
use App\Processing\Application\Knowledge\Dto\KnowledgeObservation;
use App\Processing\Application\Knowledge\LearnFromMessage;
use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeSource;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Domain\ValueObject\BillingPeriod;

use function array_filter;
use function array_map;
use function array_values;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Lo que el sistema aprende de un mensaje (ARCHITECTURE.md §13.12).
 *
 * La regla que se protege aquí es la de los **guardas**: no se aprende de
 * cualquier correo. Un mensaje sin proveedor conocido o con un tipo de
 * documento «otro» no genera conocimiento, porque aprender de un documento mal
 * leído es la forma más rápida de contaminar el buzón.
 */
#[CoversClass(LearnFromMessage::class)]
final class LearnFromMessageTest extends TestCase
{
    private const NOW = '2026-10-05 10:00:00';

    private Uuid $accountId;
    private Uuid $organizationId;
    private Uuid $providerId;

    private MailboxKnowledgeRepositoryInterface&MockObject $entries;
    private LearnFromMessage $learner;

    /**
     * @var list<MailboxKnowledgeEntry>
     */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->accountId = Uuid::v7();
        $this->organizationId = Uuid::v7();
        $this->providerId = Uuid::v7();

        $this->entries = $this->createMock(MailboxKnowledgeRepositoryInterface::class);
        $this->entries->method('findByValue')->willReturn(null);

        $this->learner = new LearnFromMessage(
            $this->entries,
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );
    }

    public function testItLearnsTheSenderAndTheDomainOfAKnownProvider(): void
    {
        $this->captureSaved();

        ($this->learner)($this->observation());

        $types = $this->typesOf($this->saved);

        self::assertContains(MailboxKnowledgeType::SENDER_MAPPING, $types);
        self::assertContains(MailboxKnowledgeType::PROVIDER_HINT, $types);
    }

    public function testItDoesNotLearnTheSenderWithoutAKnownProvider(): void
    {
        $this->captureSaved();

        $observation = new KnowledgeObservation(
            emailAccountId: $this->accountId,
            organizationId: $this->organizationId,
            sender: 'facturacion@ovh.com',
            senderDomain: 'ovh.com',
            subject: 'Factura 2026-01-15 - 45,90 €',
            attachmentNames: ['factura-2026-01-15.pdf'],
            providerId: null,
            documentType: DocumentType::INVOICE,
        );

        ($this->learner)($observation);

        $types = $this->typesOf($this->saved);

        self::assertNotContains(MailboxKnowledgeType::SENDER_MAPPING, $types);
        self::assertNotContains(MailboxKnowledgeType::PROVIDER_HINT, $types);
    }

    public function testItDoesNotLearnTheTemplatesFromAnUnknownDocumentType(): void
    {
        $this->captureSaved();

        $observation = new KnowledgeObservation(
            emailAccountId: $this->accountId,
            organizationId: $this->organizationId,
            sender: 'facturacion@ovh.com',
            senderDomain: 'ovh.com',
            subject: 'Factura 2026-01-15 - 45,90 €',
            providerId: $this->providerId,
            documentType: DocumentType::OTHER,
        );

        ($this->learner)($observation);

        $types = $this->typesOf($this->saved);

        self::assertNotContains(MailboxKnowledgeType::SUBJECT_PATTERN, $types);
        self::assertNotContains(MailboxKnowledgeType::DOCUMENT_PATTERN, $types);
    }

    public function testItLearnsTheSubjectAndAttachmentTemplates(): void
    {
        $this->captureSaved();

        ($this->learner)($this->observation());

        $types = $this->typesOf($this->saved);

        self::assertContains(MailboxKnowledgeType::SUBJECT_PATTERN, $types);
        self::assertContains(MailboxKnowledgeType::DOCUMENT_PATTERN, $types);
    }

    public function testItLearnsAtMostThreeAttachmentTemplates(): void
    {
        $this->captureSaved();

        ($this->learner)($this->observation(attachments: ['a.pdf', 'b.pdf', 'c.pdf', 'd.pdf', 'e.pdf']));

        $patterns = array_filter(
            $this->saved,
            static fn (MailboxKnowledgeEntry $e): bool => MailboxKnowledgeType::DOCUMENT_PATTERN === $e->getType(),
        );

        self::assertCount(3, $patterns);
    }

    public function testTheSenderIsStoredInLowercase(): void
    {
        $this->captureSaved();

        ($this->learner)($this->observation(sender: 'Facturacion@OVH.com'));

        $senders = array_values(array_filter(
            $this->saved,
            static fn (MailboxKnowledgeEntry $e): bool => MailboxKnowledgeType::SENDER_MAPPING === $e->getType(),
        ));

        self::assertCount(1, $senders);
        self::assertSame('facturacion@ovh.com', $senders[0]->getValue());
    }

    public function testItReinforcesAnEntryThatAlreadyExists(): void
    {
        $existing = $this->existingEntry();

        $this->entries = $this->createMock(MailboxKnowledgeRepositoryInterface::class);
        $this->entries->method('findByValue')->willReturnCallback(
            static fn (Uuid $accountId, MailboxKnowledgeType $type, string $value): ?MailboxKnowledgeEntry => MailboxKnowledgeType::SENDER_MAPPING === $type ? $existing : null,
        );
        $this->entries->expects(self::exactly(4))->method('save');

        $learner = new LearnFromMessage(
            $this->entries,
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );

        $learner($this->observation());

        self::assertSame(80, $existing->getConfidence());
        self::assertSame(BillingPeriod::MONTHLY, $existing->getBillingPeriod());
    }

    public function testAnAuthoritativeObservationReplacesTheObservedData(): void
    {
        $existing = $this->existingEntry();

        $this->entries = $this->createMock(MailboxKnowledgeRepositoryInterface::class);
        $this->entries->method('findByValue')->willReturnCallback(
            static fn (Uuid $accountId, MailboxKnowledgeType $type, string $value): ?MailboxKnowledgeEntry => MailboxKnowledgeType::SENDER_MAPPING === $type ? $existing : null,
        );

        $learner = new LearnFromMessage(
            $this->entries,
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );

        $learner($this->observation(
            source: MailboxKnowledgeSource::CONFIRMATION,
            billingPeriod: BillingPeriod::ANNUAL,
        ));

        self::assertSame(BillingPeriod::ANNUAL, $existing->getBillingPeriod());
        self::assertSame(MailboxKnowledgeSource::USER, $existing->getSource());
    }

    public function testAnObservedObservationDoesNotReplaceWhatIsKnown(): void
    {
        $existing = $this->existingEntry();

        $this->entries = $this->createMock(MailboxKnowledgeRepositoryInterface::class);
        $this->entries->method('findByValue')->willReturnCallback(
            static fn (Uuid $accountId, MailboxKnowledgeType $type, string $value): ?MailboxKnowledgeEntry => MailboxKnowledgeType::SENDER_MAPPING === $type ? $existing : null,
        );

        $learner = new LearnFromMessage(
            $this->entries,
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );

        $learner($this->observation(billingPeriod: BillingPeriod::ANNUAL));

        self::assertSame(BillingPeriod::MONTHLY, $existing->getBillingPeriod());
    }

    /**
     * Captura lo que el servicio guarda, para poder afirmar sobre ello.
     */
    private function captureSaved(): void
    {
        $this->saved = [];

        $this->entries->method('save')->willReturnCallback(function (MailboxKnowledgeEntry $entry): void {
            $this->saved[] = $entry;
        });
    }

    /**
     * @param list<MailboxKnowledgeEntry> $entries
     *
     * @return list<MailboxKnowledgeType>
     */
    private function typesOf(array $entries): array
    {
        return array_map(static fn (MailboxKnowledgeEntry $e): MailboxKnowledgeType => $e->getType(), $entries);
    }

    private function existingEntry(): MailboxKnowledgeEntry
    {
        return new MailboxKnowledgeEntry(
            emailAccountId: $this->accountId,
            organizationId: $this->organizationId,
            type: MailboxKnowledgeType::SENDER_MAPPING,
            value: 'facturacion@ovh.com',
            providerId: $this->providerId,
            billingPeriod: BillingPeriod::MONTHLY,
            source: MailboxKnowledgeSource::OBSERVED,
            confidence: 60,
            now: new DateTimeImmutable(self::NOW),
        );
    }

    /**
     * @param list<string> $attachments
     */
    private function observation(
        ?BillingPeriod $billingPeriod = null,
        MailboxKnowledgeSource $source = MailboxKnowledgeSource::OBSERVED,
        array $attachments = ['factura-2026-01-15.pdf'],
        string $sender = 'facturacion@ovh.com',
    ): KnowledgeObservation {
        return new KnowledgeObservation(
            emailAccountId: $this->accountId,
            organizationId: $this->organizationId,
            sender: $sender,
            senderDomain: 'ovh.com',
            subject: 'Factura 2026-01-15 - 45,90 €',
            attachmentNames: $attachments,
            providerId: $this->providerId,
            documentType: DocumentType::INVOICE,
            billingPeriod: $billingPeriod,
            source: $source,
            confidence: 80,
        );
    }
}
