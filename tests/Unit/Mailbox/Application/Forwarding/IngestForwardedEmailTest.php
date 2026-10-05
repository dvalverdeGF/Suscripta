<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailbox\Application\Forwarding;

use App\Mailbox\Application\Forwarding\ForwardedEmail;
use App\Mailbox\Application\Forwarding\ForwardingIngestStatus;
use App\Mailbox\Application\Forwarding\IngestForwardedEmail;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\EmailMessageSource;
use App\Processing\Application\Message\ProcessEmailMessageMessage;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Tests\Support\Mailbox\InMemoryEmailAccountRepository;
use App\Tests\Support\Mailbox\InMemoryEmailMessageRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La puerta de entrada pública del sistema. Cada rechazo es una defensa
 * (SECURITY.md §2.4), así que se prueban una a una.
 */
#[CoversClass(IngestForwardedEmail::class)]
#[CoversClass(ForwardedEmail::class)]
#[CoversClass(ForwardingIngestStatus::class)]
final class IngestForwardedEmailTest extends TestCase
{
    private const string ADDRESS = 'inbox-abc@inbound.suscripta.app';

    private InMemoryEmailAccountRepository $accounts;
    private InMemoryEmailMessageRepository $messages;
    private MessageBusInterface&MockObject $bus;
    private RateLimiterFactoryInterface&MockObject $limiterFactory;
    private LimiterInterface&MockObject $limiter;
    private AuditLoggerInterface&MockObject $auditLogger;

    /** @var list<array{action: AuditAction, targetType: ?string, targetId: ?string, metadata: array<string, scalar|null>}> */
    private array $audited = [];

    private bool $rateLimitAccepted = true;

    private EmailAccount $account;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryEmailAccountRepository();
        $this->messages = new InMemoryEmailMessageRepository();
        $this->bus = $this->createMock(MessageBusInterface::class);
        // `Envelope` es final, así que el doble no puede generarlo: se
        // devuelve uno real.
        $this->bus
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);

        $this->limiter = $this->createMock(LimiterInterface::class);
        $this->limiter
            ->method('consume')
            ->willReturnCallback(fn (): RateLimit => new RateLimit(
                availableTokens: $this->rateLimitAccepted ? 10 : 0,
                retryAfter: new DateTimeImmutable('+1 hour'),
                accepted: $this->rateLimitAccepted,
                limit: 120,
            ));

        $this->limiterFactory = $this->createMock(RateLimiterFactoryInterface::class);
        $this->limiterFactory->method('create')->willReturn($this->limiter);

        $this->audited = [];
        $this->auditLogger
            ->method('log')
            ->willReturnCallback(function (
                AuditAction $action,
                ?string $targetType = null,
                ?string $targetId = null,
                array $metadata = [],
            ): void {
                $this->audited[] = [
                    'action' => $action,
                    'targetType' => $targetType,
                    'targetId' => $targetId,
                    'metadata' => $metadata,
                ];
            });

        $this->account = new EmailAccount(Uuid::v7(), 'yo@ovh.com');
        $this->account->enableForwarding(self::ADDRESS, ['yo@ovh.com']);
        $this->accounts->save($this->account);
    }

    private function ingest(): IngestForwardedEmail
    {
        return new IngestForwardedEmail(
            accounts: $this->accounts,
            messages: $this->messages,
            bus: $this->bus,
            inboundEmailLimiter: $this->limiterFactory,
            tenantContext: new TenantContext(),
            auditLogger: $this->auditLogger,
            clock: new Clock(new MockClock(new DateTimeImmutable('2026-10-05 10:00:00'))),
        );
    }

    private function email(
        string $to = self::ADDRESS,
        string $from = 'yo@ovh.com',
        string $subject = 'Factura OVH 29,90 €',
        string $body = 'Factura mensual de OVH por 29,90 €',
        ?string $messageId = '<abc@ovh.com>',
        int $sizeBytes = 4096,
    ): ForwardedEmail {
        return new ForwardedEmail(
            toAddress: $to,
            fromAddress: $from,
            fromName: 'OVH',
            subject: $subject,
            messageId: $messageId,
            receivedAt: new DateTimeImmutable('2026-10-05 09:00:00'),
            textBody: $body,
            sizeBytes: $sizeBytes,
        );
    }

    public function testAnAuthorizedForwardIsAccepted(): void
    {
        $result = ($this->ingest())($this->email());

        self::assertTrue($result->status->isAccepted());
        self::assertNotNull($result->message);
        self::assertSame(EmailMessageSource::FORWARDING, $result->message->getSource());
        self::assertNull($result->message->getUid());
        self::assertSame(IngestForwardedEmail::FOLDER, $result->message->getFolder());
        self::assertSame('yo@ovh.com', $result->message->getFromAddress());
        self::assertSame('ovh.com', $result->message->getSenderDomain());
        self::assertSame('<abc@ovh.com>', $result->message->getMessageId());
    }

    public function testTheAcceptedMessageIsQueuedForThePipeline(): void
    {
        $dispatched = [];

        $this->bus
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });

        $result = ($this->ingest())($this->email());

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(ProcessEmailMessageMessage::class, $dispatched[0]);
        self::assertNotNull($result->message);
        self::assertSame($result->message->getId()->toRfc4122(), $dispatched[0]->emailMessageId->toRfc4122());
        self::assertSame('Factura mensual de OVH por 29,90 €', $dispatched[0]->body);
    }

    public function testTheBodyTravelsWithTheMessageBecauseThereIsNoMailbox(): void
    {
        $dispatched = [];

        $this->bus
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });

        ($this->ingest())($this->email(body: 'Cuerpo de la factura'));

        self::assertInstanceOf(ProcessEmailMessageMessage::class, $dispatched[0]);
        self::assertSame('Cuerpo de la factura', $dispatched[0]->body);
    }

    public function testAnUnknownRecipientIsRejected(): void
    {
        $result = ($this->ingest())($this->email(to: 'inbox-zzz@inbound.suscripta.app'));

        self::assertSame(ForwardingIngestStatus::UNKNOWN_RECIPIENT, $result->status);
        self::assertNull($result->message);
        self::assertSame([], $this->messages->all());
    }

    public function testADisabledForwardingAddressIsRejected(): void
    {
        $this->account->disableForwarding();

        $result = ($this->ingest())($this->email());

        self::assertSame(ForwardingIngestStatus::UNKNOWN_RECIPIENT, $result->status);
        self::assertSame([], $this->messages->all());
    }

    public function testAnUnauthorizedSenderIsRejectedAndAudited(): void
    {
        $result = ($this->ingest())($this->email(from: 'atacante@ejemplo.com'));

        self::assertSame(ForwardingIngestStatus::UNAUTHORIZED_SENDER, $result->status);
        self::assertTrue($result->status->isSuspicious());
        self::assertNull($result->message);
        self::assertSame([], $this->messages->all());

        self::assertCount(1, $this->audited);
        self::assertSame(AuditAction::EMAIL_FORWARDING_REJECTED, $this->audited[0]['action']);
        self::assertSame('unauthorized_sender', $this->audited[0]['metadata']['reason']);
        self::assertSame('atacante@ejemplo.com', $this->audited[0]['metadata']['from']);
    }

    public function testAnOversizedMessageIsRejected(): void
    {
        $result = ($this->ingest())($this->email(sizeBytes: IngestForwardedEmail::MAX_SIZE_BYTES + 1));

        self::assertSame(ForwardingIngestStatus::TOO_LARGE, $result->status);
        self::assertTrue($result->status->isSuspicious());
        self::assertSame([], $this->messages->all());
    }

    public function testAMessageAtTheSizeLimitIsAccepted(): void
    {
        $result = ($this->ingest())($this->email(sizeBytes: IngestForwardedEmail::MAX_SIZE_BYTES));

        self::assertTrue($result->status->isAccepted());
    }

    public function testTheRateLimitIsAppliedPerAccount(): void
    {
        $this->rateLimitAccepted = false;

        $result = ($this->ingest())($this->email());

        self::assertSame(ForwardingIngestStatus::RATE_LIMITED, $result->status);
        self::assertTrue($result->status->isSuspicious());
        self::assertSame([], $this->messages->all());
    }

    public function testTheSameMessageForwardedTwiceIsStoredOnce(): void
    {
        $ingest = $this->ingest();

        $first = $ingest($this->email());
        $second = $ingest($this->email());

        self::assertTrue($first->status->isAccepted());
        self::assertSame(ForwardingIngestStatus::DUPLICATE, $second->status);
        self::assertFalse($second->status->isAccepted());
        self::assertCount(1, $this->messages->all());
    }

    public function testTwoDifferentMessagesCoexistInTheSameAccount(): void
    {
        $ingest = $this->ingest();

        $ingest($this->email(messageId: '<uno@ovh.com>'));
        $ingest($this->email(messageId: '<dos@ovh.com>'));

        self::assertCount(2, $this->messages->all());
    }

    /**
     * El `uid` es nulo en los reenvíos, así que la clave `(cuenta, carpeta, uid)`
     * no puede ser la que deduplica: lo hace el `messageId`.
     */
    public function testMessagesWithoutAMessageIdAreNotDeduplicatedByUid(): void
    {
        $ingest = $this->ingest();

        $ingest($this->email(messageId: null));
        $ingest($this->email(messageId: null));

        self::assertCount(2, $this->messages->all());
    }

    public function testTheContentHashMatchesThePipelineNormalisation(): void
    {
        $result = ($this->ingest())($this->email(body: "Factura   de OVH\n\n29,90 €"));

        self::assertNotNull($result->message);
        self::assertSame(
            IngestForwardedEmail::contentHash("Factura   de OVH\n\n29,90 €"),
            $result->message->getContentHash(),
        );
        self::assertSame(
            IngestForwardedEmail::contentHash('Factura de OVH 29,90 €'),
            IngestForwardedEmail::contentHash("  FACTURA de ovh\t29,90 €  "),
        );
    }

    public function testTheIngestIsAudited(): void
    {
        $result = ($this->ingest())($this->email());

        self::assertCount(1, $this->audited);
        self::assertSame(AuditAction::EMAIL_FORWARDING_RECEIVED, $this->audited[0]['action']);
        self::assertSame('email_message', $this->audited[0]['targetType']);
        self::assertNotNull($result->message);
        self::assertSame($result->message->getId()->toRfc4122(), $this->audited[0]['targetId']);
    }

    public function testTheMessageBelongsToTheAccountOrganization(): void
    {
        $result = ($this->ingest())($this->email());

        self::assertNotNull($result->message);
        self::assertSame(
            $this->account->getOrganizationId()->toRfc4122(),
            $result->message->getOrganizationId()->toRfc4122(),
        );
    }

    public function testTheRecipientIsRecordedWhenTheProviderDoesNotSendTheList(): void
    {
        $result = ($this->ingest())($this->email());

        self::assertNotNull($result->message);
        self::assertSame([self::ADDRESS], $result->message->getToAddresses());
    }

    public function testTheHtmlBodyIsUsedWhenThereIsNoPlainText(): void
    {
        $email = new ForwardedEmail(
            toAddress: self::ADDRESS,
            fromAddress: 'yo@ovh.com',
            fromName: 'OVH',
            subject: 'Factura',
            messageId: '<html@ovh.com>',
            receivedAt: new DateTimeImmutable('2026-10-05 09:00:00'),
            textBody: '',
            htmlBody: '<p>Factura de 29,90 €</p>',
        );

        $result = ($this->ingest())($email);

        self::assertNotNull($result->message);
        self::assertSame('text/html', $result->message->getContentType());
        self::assertSame(
            IngestForwardedEmail::contentHash('<p>Factura de 29,90 €</p>'),
            $result->message->getContentHash(),
        );
    }

    public function testTheReceivedDateFallsBackToNowWhenTheProviderOmitsIt(): void
    {
        $email = new ForwardedEmail(
            toAddress: self::ADDRESS,
            fromAddress: 'yo@ovh.com',
            fromName: 'OVH',
            subject: 'Factura',
            messageId: '<sin-fecha@ovh.com>',
            receivedAt: null,
            textBody: 'Factura',
        );

        $result = ($this->ingest())($email);

        self::assertNotNull($result->message);

        $receivedAt = $result->message->getReceivedAt();

        self::assertNotNull($receivedAt);
        self::assertSame('2026-10-05 10:00:00', $receivedAt->format('Y-m-d H:i:s'));
    }

    public function testTheAttachmentNamesAreKept(): void
    {
        $email = new ForwardedEmail(
            toAddress: self::ADDRESS,
            fromAddress: 'yo@ovh.com',
            fromName: 'OVH',
            subject: 'Factura',
            messageId: '<adj@ovh.com>',
            receivedAt: new DateTimeImmutable('2026-10-05 09:00:00'),
            textBody: 'Adjunto la factura',
            attachmentNames: ['factura-2026-10.pdf'],
            attachmentTypes: ['application/pdf'],
        );

        $result = ($this->ingest())($email);

        self::assertNotNull($result->message);
        self::assertTrue($result->message->hasAttachments());
        self::assertSame(['factura-2026-10.pdf'], $result->message->getAttachmentNames());
    }

    public function testTheRejectionReasonsAreDistinguishableInCode(): void
    {
        self::assertTrue(ForwardingIngestStatus::ACCEPTED->isAccepted());
        self::assertFalse(ForwardingIngestStatus::DUPLICATE->isAccepted());
        self::assertFalse(ForwardingIngestStatus::UNKNOWN_RECIPIENT->isAccepted());
        self::assertFalse(ForwardingIngestStatus::FORWARDING_DISABLED->isAccepted());
        self::assertFalse(ForwardingIngestStatus::UNAUTHORIZED_SENDER->isAccepted());
        self::assertFalse(ForwardingIngestStatus::TOO_LARGE->isAccepted());
        self::assertFalse(ForwardingIngestStatus::RATE_LIMITED->isAccepted());

        self::assertTrue(ForwardingIngestStatus::UNAUTHORIZED_SENDER->isSuspicious());
        self::assertTrue(ForwardingIngestStatus::TOO_LARGE->isSuspicious());
        self::assertTrue(ForwardingIngestStatus::RATE_LIMITED->isSuspicious());
        self::assertFalse(ForwardingIngestStatus::DUPLICATE->isSuspicious());
        self::assertFalse(ForwardingIngestStatus::UNKNOWN_RECIPIENT->isSuspicious());
    }
}
