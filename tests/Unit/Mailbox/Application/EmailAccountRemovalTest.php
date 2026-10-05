<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailbox\Application;

use App\Mailbox\Application\DeleteEmailAccount;
use App\Mailbox\Application\DisconnectEmailAccount;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Entity\EmailSyncCursor;
use App\Mailbox\Domain\Enum\EmailAccountStatus;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Domain\Enum\AuditAction;
use App\Tests\Support\Mailbox\InMemoryEmailAccountRepository;
use App\Tests\Support\Mailbox\InMemoryEmailMessageRepository;
use App\Tests\Support\Mailbox\InMemoryEmailSyncCursorRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function sprintf;

use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Desconectar y borrar un buzón (SECURITY.md §6).
 *
 * Son las dos operaciones que materializan el derecho de supresión sobre el
 * correo. La diferencia importa: desconectar destruye credenciales, mensajes y
 * cursores pero conserva la cuenta para poder reconectar; borrar además la
 * marca como eliminada. En ninguno de los dos casos se tocan los servicios ya
 * confirmados, porque son datos del usuario y no del correo.
 */
#[CoversClass(DisconnectEmailAccount::class)]
#[CoversClass(DeleteEmailAccount::class)]
final class EmailAccountRemovalTest extends TestCase
{
    private Uuid $organizationId;

    private InMemoryEmailAccountRepository $accounts;

    private InMemoryEmailMessageRepository $messages;

    private InMemoryEmailSyncCursorRepository $cursors;

    private AuditLoggerInterface&MockObject $audit;

    /** @var list<AuditAction> */
    private array $audited = [];

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->accounts = new InMemoryEmailAccountRepository();
        $this->messages = new InMemoryEmailMessageRepository();
        $this->cursors = new InMemoryEmailSyncCursorRepository();
        $this->audit = $this->createMock(AuditLoggerInterface::class);
        $this->audited = [];

        $this->audit
            ->method('log')
            ->willReturnCallback(function (AuditAction $action): void {
                $this->audited[] = $action;
            });
    }

    private function account(): EmailAccount
    {
        $account = new EmailAccount($this->organizationId, 'yo@ovh.com');
        $account->configureImap('imap.ovh.com', ImapEncryption::SSL, 993, 'yo@ovh.com');
        $account->setCredentialsEncrypted('cifrado');
        $account->markActive();
        $account->enableForwarding('inbox-abc@inbound.suscripta.local', ['yo@ovh.com']);

        $this->accounts->save($account);

        return $account;
    }

    private function message(EmailAccount $account, int $uid): EmailMessage
    {
        $message = new EmailMessage($this->organizationId, $account->getId(), 'INBOX', $uid);
        $message->applyMetadata(
            messageId: sprintf('<%d@ovh.com>', $uid),
            fromAddress: 'facturacion@ovh.com',
            fromName: 'OVH',
            replyTo: null,
            senderDomain: 'ovh.com',
            toAddresses: ['yo@ovh.com'],
            subject: 'Factura',
            receivedAt: new DateTimeImmutable('2026-10-03 08:00:00'),
            sizeBytes: 2048,
            contentType: 'multipart/mixed',
            attachmentNames: ['factura.pdf'],
            attachmentTypes: ['application/pdf'],
        );

        $this->messages->save($message);

        return $message;
    }

    private function cursor(EmailAccount $account): EmailSyncCursor
    {
        $cursor = new EmailSyncCursor($this->organizationId, $account->getId(), 'INBOX');
        $cursor->observeUidValidity(1);
        $cursor->advanceTo(42);

        $this->cursors->save($cursor);

        return $cursor;
    }

    private function disconnect(): DisconnectEmailAccount
    {
        return new DisconnectEmailAccount(
            accounts: $this->accounts,
            messages: $this->messages,
            cursors: $this->cursors,
            auditLogger: $this->audit,
        );
    }

    private function delete(): DeleteEmailAccount
    {
        return new DeleteEmailAccount(
            accounts: $this->accounts,
            messages: $this->messages,
            auditLogger: $this->audit,
            clock: new Clock(new MockClock(new DateTimeImmutable('2026-10-05 10:00:00'))),
        );
    }

    public function testDisconnectingDestroysTheCredentials(): void
    {
        $account = $this->account();

        ($this->disconnect())($account);

        self::assertNull($account->getCredentialsEncrypted());
        self::assertFalse($account->hasCredentials());
        self::assertFalse($account->isConfigured());
        self::assertSame(EmailAccountStatus::DISABLED, $account->getStatus());
    }

    public function testDisconnectingRemovesTheIndexedMessages(): void
    {
        $account = $this->account();
        $this->message($account, 1);
        $this->message($account, 2);

        self::assertCount(2, $this->messages->all());

        ($this->disconnect())($account);

        self::assertSame([], $this->messages->all());
    }

    public function testDisconnectingRemovesTheSyncCursors(): void
    {
        $account = $this->account();
        $this->cursor($account);

        self::assertCount(1, $this->cursors->all());

        ($this->disconnect())($account);

        self::assertSame([], $this->cursors->all());
    }

    public function testDisconnectingStopsAcceptingForwardedMail(): void
    {
        $account = $this->account();

        self::assertTrue($account->isForwardingEnabled());

        ($this->disconnect())($account);

        self::assertFalse($account->isForwardingEnabled());
        self::assertNull($this->accounts->findByForwardingAddress('inbox-abc@inbound.suscripta.local'));
    }

    public function testDisconnectingKeepsTheAccountSoItCanBeReconnected(): void
    {
        $account = $this->account();

        ($this->disconnect())($account);

        self::assertFalse($account->isDeleted());
        self::assertCount(1, $this->accounts->all());
        self::assertSame('yo@ovh.com', $account->getEmailAddress());
    }

    public function testDisconnectingIsAuditedWithWhatWasRemoved(): void
    {
        $account = $this->account();
        $this->message($account, 1);
        $this->cursor($account);

        ($this->disconnect())($account);

        self::assertSame([AuditAction::EMAIL_ACCOUNT_DISCONNECTED], $this->audited);
    }

    public function testDeletingRemovesTheMessagesAndMarksTheAccountAsDeleted(): void
    {
        $account = $this->account();
        $this->message($account, 1);

        ($this->delete())($account);

        self::assertSame([], $this->messages->all());
        self::assertTrue($account->isDeleted());
        self::assertNotNull($account->getDeletedAt());
        self::assertSame([AuditAction::EMAIL_ACCOUNT_DELETED], $this->audited);
    }

    public function testDeletingDoesNotTouchAnotherAccountsMessages(): void
    {
        $mine = $this->account();
        $this->message($mine, 1);

        $other = new EmailAccount($this->organizationId, 'otro@ovh.com');
        $other->configureImap('imap.ovh.com', ImapEncryption::SSL, 993, 'otro@ovh.com');
        $other->setCredentialsEncrypted('cifrado');
        $this->accounts->save($other);
        $this->message($other, 1);

        ($this->delete())($mine);

        self::assertCount(1, $this->messages->all());
        self::assertSame($other->getId()->toRfc4122(), $this->messages->all()[0]->getEmailAccountId()->toRfc4122());
    }
}
