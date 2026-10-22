<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Knowledge;

use App\Catalog\Domain\Entity\Provider;
use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Documents\Domain\Enum\DocumentType;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Application\Knowledge\ResolveMailboxKnowledge;
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
 * El conocimiento del buzón aplicado a un mensaje (ARCHITECTURE.md §13.12).
 *
 * La propiedad que se prueba aquí es que el conocimiento es **del buzón**:
 * una entrada de una cuenta no puede afectar a otra. Es la razón de que el
 * repositorio se consulte siempre por `emailAccountId` y no por valor.
 */
#[CoversClass(ResolveMailboxKnowledge::class)]
final class ResolveMailboxKnowledgeTest extends TestCase
{
    private const NOW = '2026-10-05 10:00:00';

    private Uuid $accountId;
    private Uuid $otherAccountId;
    private Uuid $organizationId;

    private MailboxKnowledgeRepositoryInterface&MockObject $entries;
    private ProviderRepositoryInterface&MockObject $providers;
    private ResolveMailboxKnowledge $resolver;

    protected function setUp(): void
    {
        $this->accountId = Uuid::v7();
        $this->otherAccountId = Uuid::v7();
        $this->organizationId = Uuid::v7();

        $this->entries = $this->createMock(MailboxKnowledgeRepositoryInterface::class);
        $this->providers = $this->createMock(ProviderRepositoryInterface::class);

        $this->resolver = new ResolveMailboxKnowledge(
            $this->entries,
            $this->providers,
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );
    }

    public function testAnEmptyMailboxKnowsNothing(): void
    {
        $this->entries->method('findForAccount')->willReturn([]);

        $knowledge = ($this->resolver)($this->accountId, $this->header());

        self::assertFalse($knowledge->isIgnored());
        self::assertFalse($knowledge->hasProvider());
        self::assertNull($knowledge->documentType);
        self::assertNull($knowledge->billingPeriod);
        self::assertSame([], $knowledge->matched);
    }

    public function testItResolvesTheProviderFromTheSender(): void
    {
        $provider = new Provider('OVHcloud', 'ovh');
        $this->entries->method('findForAccount')->willReturn([
            $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'facturacion@ovh.com', providerId: $provider->getId()),
        ]);
        $this->providers->method('find')->with($provider->getId())->willReturn($provider);

        $knowledge = ($this->resolver)($this->accountId, $this->header());

        self::assertTrue($knowledge->hasProvider());
        self::assertSame('OVHcloud', $knowledge->providerName());
        self::assertTrue($provider->getId()->equals($knowledge->providerId()));
    }

    public function testTheDomainIsTheFallbackWhenTheSenderIsUnknown(): void
    {
        $provider = new Provider('OVHcloud', 'ovh');
        $this->entries->method('findForAccount')->willReturn([
            $this->entry(MailboxKnowledgeType::PROVIDER_HINT, 'ovh.com', providerId: $provider->getId()),
        ]);
        $this->providers->method('find')->willReturn($provider);

        $knowledge = ($this->resolver)($this->accountId, $this->header(from: 'otra-caja@ovh.com'));

        self::assertTrue($knowledge->hasProvider());
    }

    public function testTheExactSenderWinsOverTheDomain(): void
    {
        $domainProvider = new Provider('Otro', 'otro');
        $senderProvider = new Provider('OVHcloud', 'ovh');
        $this->entries->method('findForAccount')->willReturn([
            $this->entry(MailboxKnowledgeType::PROVIDER_HINT, 'ovh.com', providerId: $domainProvider->getId()),
            $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'facturacion@ovh.com', providerId: $senderProvider->getId()),
        ]);
        $this->providers->method('find')->with($senderProvider->getId())->willReturn($senderProvider);

        $knowledge = ($this->resolver)($this->accountId, $this->header());

        self::assertTrue($senderProvider->getId()->equals($knowledge->providerId()));
    }

    public function testItReadsTheBillingPeriodLearnedForTheSender(): void
    {
        $provider = new Provider('OVHcloud', 'ovh');
        $this->entries->method('findForAccount')->willReturn([
            $this->entry(
                MailboxKnowledgeType::SENDER_MAPPING,
                'facturacion@ovh.com',
                providerId: $provider->getId(),
                billingPeriod: BillingPeriod::MONTHLY,
            ),
        ]);
        $this->providers->method('find')->willReturn($provider);

        $knowledge = ($this->resolver)($this->accountId, $this->header());

        self::assertSame(BillingPeriod::MONTHLY, $knowledge->billingPeriod);
    }

    public function testAnIgnoredSenderSkipsTheMessage(): void
    {
        $this->entries->method('findForAccount')->willReturn([
            $this->entry(MailboxKnowledgeType::IGNORED_SENDER, 'facturacion@ovh.com', confidence: 60),
        ]);

        $knowledge = ($this->resolver)($this->accountId, $this->header());

        self::assertTrue($knowledge->isIgnored());
    }

    public function testALowConfidenceIgnoreDoesNotSkipTheMessage(): void
    {
        $this->entries->method('findForAccount')->willReturn([
            $this->entry(MailboxKnowledgeType::IGNORED_SENDER, 'facturacion@ovh.com', confidence: 30),
        ]);

        $knowledge = ($this->resolver)($this->accountId, $this->header());

        self::assertFalse($knowledge->isIgnored());
    }

    public function testTheDocumentTypeComesFromTheAttachmentTemplate(): void
    {
        $this->entries->method('findForAccount')->willReturn([
            $this->entry(
                MailboxKnowledgeType::SUBJECT_PATTERN,
                '/^Factura/u',
                documentType: DocumentType::RECEIPT,
            ),
            $this->entry(
                MailboxKnowledgeType::DOCUMENT_PATTERN,
                '/^factura/u',
                documentType: DocumentType::INVOICE,
            ),
        ]);

        $knowledge = ($this->resolver)($this->accountId, $this->header(attachments: ['factura-2026-01.pdf']));

        self::assertSame(DocumentType::INVOICE, $knowledge->documentType);
    }

    public function testTheSubjectTemplateIsUsedWhenThereIsNoAttachment(): void
    {
        $this->entries->method('findForAccount')->willReturn([
            $this->entry(
                MailboxKnowledgeType::SUBJECT_PATTERN,
                '/^Factura/u',
                documentType: DocumentType::INVOICE,
            ),
        ]);

        $knowledge = ($this->resolver)($this->accountId, $this->header());

        self::assertSame(DocumentType::INVOICE, $knowledge->documentType);
    }

    public function testTheKnowledgeIsAlwaysQueriedByAccount(): void
    {
        $queried = [];

        $this->entries->method('findForAccount')
            ->willReturnCallback(static function (Uuid $accountId) use (&$queried): array {
                $queried[] = $accountId;

                return [];
            });

        ($this->resolver)($this->accountId, $this->header());
        ($this->resolver)($this->otherAccountId, $this->header());

        self::assertCount(2, $queried);
        self::assertTrue($this->accountId->equals($queried[0]));
        self::assertTrue($this->otherAccountId->equals($queried[1]));
    }

    public function testMatchedEntriesAreMarkedAsUsed(): void
    {
        $provider = new Provider('OVHcloud', 'ovh');
        $entry = $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'facturacion@ovh.com', providerId: $provider->getId());
        $this->entries->method('findForAccount')->willReturn([$entry]);
        $this->providers->method('find')->willReturn($provider);

        ($this->resolver)($this->accountId, $this->header());

        self::assertSame(1, $entry->getHitCount());
        self::assertNotNull($entry->getLastUsedAt());
    }

    public function testAMessageThatMatchesNothingDoesNotTouchTheRepository(): void
    {
        $this->entries->method('findForAccount')->willReturn([
            $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'otro@ovh.com', providerId: Uuid::v7()),
        ]);
        $this->entries->expects(self::never())->method('saveAll');

        $knowledge = ($this->resolver)($this->accountId, $this->header());

        self::assertFalse($knowledge->hasProvider());
    }

    private function entry(
        MailboxKnowledgeType $type,
        string $value,
        ?Uuid $providerId = null,
        ?DocumentType $documentType = null,
        ?BillingPeriod $billingPeriod = null,
        int $confidence = 60,
    ): MailboxKnowledgeEntry {
        return new MailboxKnowledgeEntry(
            emailAccountId: $this->accountId,
            organizationId: $this->organizationId,
            type: $type,
            value: $value,
            providerId: $providerId,
            documentType: $documentType,
            billingPeriod: $billingPeriod,
            source: MailboxKnowledgeSource::OBSERVED,
            confidence: $confidence,
            now: new DateTimeImmutable(self::NOW),
        );
    }

    /**
     * @param list<string> $attachments
     */
    private function header(
        string $subject = 'Factura 2026-01-15',
        string $from = 'facturacion@ovh.com',
        array $attachments = [],
    ): ImapMessageHeader {
        return new ImapMessageHeader(
            uid: 1,
            messageId: '<abc@example.com>',
            fromAddress: $from,
            fromName: 'OVH',
            replyTo: null,
            toAddresses: ['yo@ejemplo.com'],
            subject: $subject,
            receivedAt: new DateTimeImmutable(self::NOW),
            sizeBytes: 1024,
            contentType: 'text/plain',
            attachmentNames: $attachments,
            attachmentTypes: [],
        );
    }
}
