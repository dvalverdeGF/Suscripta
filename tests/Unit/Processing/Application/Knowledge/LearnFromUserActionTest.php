<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Knowledge;

use App\Discovery\Application\Dto\DiscoveryInput;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Documents\Domain\Enum\DocumentType;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Processing\Application\Knowledge\LearnFromConfirmation;
use App\Processing\Application\Knowledge\LearnFromDismissal;
use App\Processing\Application\Knowledge\LearnFromMessage;
use App\Processing\Application\Knowledge\RecordSenderRejection;
use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeSource;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * El aprendizaje que nace de las acciones del usuario (ARCHITECTURE.md §13.12).
 *
 * Confirmar y descartar son las dos correcciones que el usuario hace sobre el
 * sistema, y las dos valen más que cualquier deducción: lo que el usuario
 * afirma **pisa** lo que el sistema había deducido.
 */
#[CoversClass(LearnFromConfirmation::class)]
#[CoversClass(LearnFromDismissal::class)]
final class LearnFromUserActionTest extends TestCase
{
    private const NOW = '2026-10-05 10:00:00';

    private Uuid $organizationId;
    private Uuid $accountId;
    private Uuid $providerId;

    private EmailMessageRepositoryInterface&MockObject $messages;
    private MailboxKnowledgeRepositoryInterface&MockObject $entries;
    private EmailMessage $message;

    /**
     * @var list<MailboxKnowledgeEntry>
     */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->accountId = Uuid::v7();
        $this->providerId = Uuid::v7();

        $this->message = $this->buildMessage();

        $this->messages = $this->createMock(EmailMessageRepositoryInterface::class);
        $this->entries = $this->createMock(MailboxKnowledgeRepositoryInterface::class);
        $this->entries->method('findByValue')->willReturn(null);
        $this->entries->method('save')->willReturnCallback(function (MailboxKnowledgeEntry $entry): void {
            $this->saved[] = $entry;
        });
    }

    public function testAConfirmationLearnsTheSenderTheProviderAndThePeriod(): void
    {
        $this->messages->method('find')->willReturn($this->message);

        $confirmation = $this->confirmation();
        $confirmation($this->discovery(), $this->input());

        $types = array_map(static fn (MailboxKnowledgeEntry $e): MailboxKnowledgeType => $e->getType(), $this->saved);

        self::assertContains(MailboxKnowledgeType::SENDER_MAPPING, $types);

        $sender = $this->entryOfType(MailboxKnowledgeType::SENDER_MAPPING);

        self::assertNotNull($sender);
        self::assertTrue($this->providerId->equals($sender->getProviderId()));
        self::assertSame(BillingPeriod::ANNUAL, $sender->getBillingPeriod());
        self::assertSame(MailboxKnowledgeSource::CONFIRMATION, $sender->getSource());
    }

    public function testAConfirmationWithoutASourceMessageLearnsNothing(): void
    {
        $this->entries->expects(self::never())->method('save');

        $discovery = new Discovery($this->organizationId, DiscoveryType::NEW_SERVICE, new DateTimeImmutable(self::NOW));
        $discovery->attachSourceMessage(null);

        $confirmation = $this->confirmation();
        $confirmation($discovery, $this->input());
    }

    public function testAConfirmationRecoversTheDocumentTypeFromTheProposal(): void
    {
        $this->messages->method('find')->willReturn($this->message);

        $confirmation = $this->confirmation();
        $confirmation($this->discovery(), $this->input());

        $sender = $this->entryOfType(MailboxKnowledgeType::SENDER_MAPPING);

        self::assertNotNull($sender);
        self::assertSame(DocumentType::INVOICE, $sender->getDocumentType());
    }

    public function testADismissalRecordsTheRejectionOfTheSender(): void
    {
        $this->messages->method('find')->willReturn($this->message);

        $dismissal = $this->dismissal();
        $dismissal($this->discovery());

        $ignored = $this->entryOfType(MailboxKnowledgeType::IGNORED_SENDER);

        self::assertNotNull($ignored);
        self::assertSame('facturacion@ovh.com', $ignored->getValue());
        self::assertSame(30, $ignored->getConfidence());
        self::assertFalse($ignored->excludes());
    }

    public function testADismissalWithoutASourceMessageLearnsNothing(): void
    {
        $this->entries->expects(self::never())->method('save');

        $discovery = new Discovery($this->organizationId, DiscoveryType::NEW_SERVICE, new DateTimeImmutable(self::NOW));
        $discovery->attachSourceMessage(null);

        $dismissal = $this->dismissal();
        $dismissal($discovery);
    }

    private function confirmation(): LearnFromConfirmation
    {
        return new LearnFromConfirmation(
            $this->messages,
            new LearnFromMessage($this->entries, new Clock(new MockClock(new DateTimeImmutable(self::NOW)))),
        );
    }

    private function dismissal(): LearnFromDismissal
    {
        return new LearnFromDismissal(
            $this->messages,
            new RecordSenderRejection($this->entries, new Clock(new MockClock(new DateTimeImmutable(self::NOW)))),
        );
    }

    private function entryOfType(MailboxKnowledgeType $type): ?MailboxKnowledgeEntry
    {
        foreach ($this->saved as $entry) {
            if ($type === $entry->getType()) {
                return $entry;
            }
        }

        return null;
    }

    private function buildMessage(): EmailMessage
    {
        $message = new EmailMessage($this->organizationId, $this->accountId, 'INBOX', 42);
        $message->applyMetadata(
            messageId: '<abc@example.com>',
            fromAddress: 'Facturacion@OVH.com',
            fromName: 'OVH',
            replyTo: null,
            senderDomain: 'ovh.com',
            toAddresses: ['yo@ejemplo.com'],
            subject: 'Factura 2026-01-15 - 45,90 €',
            receivedAt: new DateTimeImmutable(self::NOW),
            sizeBytes: 2048,
            contentType: 'application/pdf',
            attachmentNames: ['factura-2026-01-15.pdf'],
            attachmentTypes: ['application/pdf'],
        );

        return $message;
    }

    private function discovery(): Discovery
    {
        $discovery = new Discovery(
            $this->organizationId,
            DiscoveryType::NEW_SERVICE,
            new DateTimeImmutable(self::NOW),
            ['documentType' => DocumentType::INVOICE->value, 'name' => 'OVHcloud'],
        );
        $discovery->attachSourceMessage($this->message->getId());

        return $discovery;
    }

    private function input(): DiscoveryInput
    {
        return new DiscoveryInput(
            name: 'OVHcloud',
            billingPeriod: BillingPeriod::ANNUAL,
            providerId: $this->providerId,
        );
    }
}
