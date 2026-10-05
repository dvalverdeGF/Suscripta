<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailbox\Application;

use App\Mailbox\Application\CredentialCipherInterface;
use App\Mailbox\Application\Imap\ImapClientInterface;
use App\Mailbox\Application\Imap\ImapConnectionConfig;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Mailbox\Application\SyncEmailAccount;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Entity\EmailSyncRun;
use App\Mailbox\Domain\Enum\EmailAccountStatus;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Enum\SyncRunStatus;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailSyncRunRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Tests\Support\Mailbox\InMemoryEmailSyncCursorRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function sprintf;

use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * La sincronización es el nivel 0 y 1 del pipeline y la única parte que habla
 * con el buzón. Aquí se comprueban las tres propiedades que sostienen todo lo
 * demás: que **nunca** se descarga un cuerpo, que volver a sincronizar no
 * duplica ni vuelve a costar nada (D-37), y que la lectura es incremental de
 * verdad — es decir, que la segunda pasada no vuelve a pedir lo que ya vio.
 */
#[CoversClass(SyncEmailAccount::class)]
final class SyncEmailAccountTest extends TestCase
{
    private Uuid $organizationId;

    private EmailAccountRepositoryInterface&MockObject $accounts;

    private EmailMessageRepositoryInterface&MockObject $messages;

    private EmailSyncRunRepositoryInterface&MockObject $runs;

    private ImapClientInterface&MockObject $imap;

    private CredentialCipherInterface&MockObject $cipher;

    private AuditLoggerInterface&MockObject $audit;

    private InMemoryEmailSyncCursorRepository $cursors;

    /** @var list<EmailMessage> */
    private array $saved = [];

    /** @var list<AuditAction> */
    private array $audited = [];

    /**
     * El `UIDVALIDITY` que reporta el servidor. Es una propiedad y no un
     * `willReturn` fijo porque una prueba necesita cambiarlo a mitad, y un
     * stub registrado en `setUp()` gana sobre cualquier stub posterior.
     */
    private int $uidValidity = 1;

    /** @var list<array{folder: string, afterUid: int, limit: int}> */
    private array $incrementalCalls = [];

    /** @var list<array{folder: string, beforeUid: int, limit: int}> */
    private array $backfillCalls = [];

    /**
     * Lotes que devuelve el servidor. Son propiedades y no `willReturn` porque
     * un stub registrado en `setUp()` gana sobre cualquier stub posterior, así
     * que la única forma de cambiar la respuesta a mitad de una prueba es
     * cambiar el valor que el stub lee.
     *
     * @var list<ImapMessageHeader>
     */
    private array $windowBatch = [];

    /** @var list<ImapMessageHeader> */
    private array $incrementalBatch = [];

    /** @var list<ImapMessageHeader> */
    private array $backfillBatch = [];

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();

        $this->accounts = $this->createMock(EmailAccountRepositoryInterface::class);
        $this->messages = $this->createMock(EmailMessageRepositoryInterface::class);
        $this->runs = $this->createMock(EmailSyncRunRepositoryInterface::class);
        $this->imap = $this->createMock(ImapClientInterface::class);
        $this->cipher = $this->createMock(CredentialCipherInterface::class);
        $this->audit = $this->createMock(AuditLoggerInterface::class);
        $this->cursors = new InMemoryEmailSyncCursorRepository();

        $this->saved = [];
        $this->audited = [];
        $this->uidValidity = 1;
        $this->incrementalCalls = [];
        $this->backfillCalls = [];
        $this->windowBatch = [];
        $this->incrementalBatch = [];
        $this->backfillBatch = [];

        $this->messages
            ->method('save')
            ->willReturnCallback(function (EmailMessage $message): void {
                $this->saved[] = $message;
            });

        $this->audit
            ->method('log')
            ->willReturnCallback(function (AuditAction $action): void {
                $this->audited[] = $action;
            });

        $this->cipher->method('decrypt')->willReturn('secreto');

        // Por defecto el servidor no ha renumerado nunca la carpeta.
        $this->imap->method('getUidValidity')->willReturnCallback(fn (): int => $this->uidValidity);

        $this->imap
            ->method('fetchHeaders')
            ->willReturnCallback(fn (): array => $this->windowBatch);

        $this->imap
            ->method('fetchHeadersAfter')
            ->willReturnCallback(function (ImapConnectionConfig $config, string $folder, int $afterUid, int $limit): array {
                $this->incrementalCalls[] = ['folder' => $folder, 'afterUid' => $afterUid, 'limit' => $limit];

                return $this->incrementalBatch;
            });

        $this->imap
            ->method('fetchHeadersBefore')
            ->willReturnCallback(function (ImapConnectionConfig $config, string $folder, int $beforeUid, int $limit): array {
                $this->backfillCalls[] = ['folder' => $folder, 'beforeUid' => $beforeUid, 'limit' => $limit];

                return $this->backfillBatch;
            });
    }

    private function sync(?TenantContext $tenantContext = null): SyncEmailAccount
    {
        return new SyncEmailAccount(
            accounts: $this->accounts,
            messages: $this->messages,
            runs: $this->runs,
            cursors: $this->cursors,
            imapClient: $this->imap,
            cipher: $this->cipher,
            tenantContext: $tenantContext ?? new TenantContext(),
            auditLogger: $this->audit,
            clock: new Clock(new MockClock(new DateTimeImmutable('2026-10-05 10:00:00'))),
        );
    }

    private function account(): EmailAccount
    {
        $account = new EmailAccount($this->organizationId, 'yo@ovh.com');
        $account->configureImap('imap.ovh.com', ImapEncryption::SSL, 993, 'yo@ovh.com');
        $account->setCredentialsEncrypted('cifrado');

        return $account;
    }

    private function header(int $uid, ?string $messageId = null, ?string $from = 'facturacion@ovh.com'): ImapMessageHeader
    {
        return new ImapMessageHeader(
            uid: $uid,
            messageId: $messageId ?? sprintf('<%d@ovh.com>', $uid),
            fromAddress: $from,
            fromName: 'OVH',
            replyTo: null,
            toAddresses: ['yo@ovh.com'],
            subject: 'Factura OVH',
            receivedAt: new DateTimeImmutable('2026-10-03 08:00:00'),
            sizeBytes: 2048,
            contentType: 'multipart/mixed',
            attachmentNames: ['factura.pdf'],
            attachmentTypes: ['application/pdf'],
        );
    }

    /**
     * @param list<ImapMessageHeader> $headers
     */
    private function imapReturns(array $headers): void
    {
        $this->windowBatch = $headers;
    }

    /**
     * @param list<ImapMessageHeader> $headers
     */
    private function imapReturnsBackfill(array $headers): void
    {
        $this->backfillBatch = $headers;
    }

    /**
     * @param list<ImapMessageHeader> $headers
     */
    private function imapReturnsIncremental(array $headers): void
    {
        $this->incrementalBatch = $headers;
    }

    public function testAnUnconfiguredAccountIsRejectedBeforeTouchingTheMailbox(): void
    {
        $this->imap->expects(self::never())->method('fetchHeaders');

        $this->expectException(InvalidArgumentException::class);

        ($this->sync())(new EmailAccount($this->organizationId, 'yo@ovh.com'));
    }

    public function testTheFirstSyncStoresEveryMessageAsMetadataOnly(): void
    {
        $this->imapReturns([$this->header(1), $this->header(2)]);

        $run = ($this->sync())($this->account());

        self::assertSame(SyncRunStatus::COMPLETED, $run->getStatus());
        self::assertSame(2, $run->getMessagesSeen());
        self::assertSame(2, $run->getMessagesProcessed());
        self::assertSame(0, $run->getMessagesSkipped());
        self::assertCount(2, $this->saved);
        self::assertSame('facturacion@ovh.com', $this->saved[0]->getFromAddress());
        self::assertSame('ovh.com', $this->saved[0]->getSenderDomain());
        self::assertSame(['factura.pdf'], $this->saved[0]->getAttachmentNames());
    }

    public function testTheBodyIsNeverDownloadedDuringASync(): void
    {
        $this->imapReturns([$this->header(1)]);
        $this->imap->expects(self::never())->method('fetchBody');

        ($this->sync())($this->account());

        self::assertNull($this->saved[0]->getBodyExcerpt());
        self::assertNull($this->saved[0]->getContentHash());
    }

    public function testAMessageAlreadyKnownByUidIsSkipped(): void
    {
        $account = $this->account();
        $this->imapReturns([$this->header(1), $this->header(2)]);

        $this->messages
            ->method('findByAccountFolderUid')
            ->willReturnCallback(
                static fn (Uuid $accountId, string $folder, int $uid): ?EmailMessage => 1 === $uid
                    ? new EmailMessage($accountId, $accountId, $folder, $uid)
                    : null,
            );

        $run = ($this->sync())($account);

        self::assertSame(2, $run->getMessagesSeen());
        self::assertSame(1, $run->getMessagesProcessed());
        self::assertSame(1, $run->getMessagesSkipped());
        self::assertCount(1, $this->saved);
        self::assertSame(2, $this->saved[0]->getUid());
    }

    /**
     * Un mensaje movido de carpeta cambia de UID pero conserva su
     * `Message-ID`: sin esta segunda comprobación, mover una factura a un
     * archivo la haría volver a entrar en el pipeline.
     */
    public function testAMessageMovedToAnotherFolderIsCaughtByItsMessageId(): void
    {
        $this->imapReturns([$this->header(7, '<factura-2026-10@ovh.com>')]);

        $this->messages->method('findByAccountFolderUid')->willReturn(null);
        $this->messages
            ->method('findByAccountAndMessageId')
            ->willReturn(new EmailMessage($this->organizationId, Uuid::v7(), 'Archivo', 3));

        $run = ($this->sync())($this->account());

        self::assertSame(1, $run->getMessagesSkipped());
        self::assertSame(0, $run->getMessagesProcessed());
        self::assertSame([], $this->saved);
    }

    public function testAMessageWithoutMessageIdIsOnlyDeduplicatedByUid(): void
    {
        $this->imapReturns([$this->header(7, '')]);

        $this->messages->method('findByAccountFolderUid')->willReturn(null);
        $this->messages->expects(self::never())->method('findByAccountAndMessageId');

        $run = ($this->sync())($this->account());

        self::assertSame(1, $run->getMessagesProcessed());
    }

    public function testTheSyncWindowAndTheLimitAreForwardedToTheMailbox(): void
    {
        $this->imap
            ->expects(self::once())
            ->method('fetchHeaders')
            ->with(
                self::isInstanceOf(ImapConnectionConfig::class),
                'INBOX',
                new DateTimeImmutable('2026-04-05 10:00:00'),
                50,
            )
            ->willReturn([]);

        ($this->sync())($this->account(), months: 6, limit: 50);
    }

    public function testTheAccountIsMarkedActiveAfterASuccessfulSync(): void
    {
        $this->imapReturns([]);

        $account = $this->account();
        ($this->sync())($account);

        self::assertSame(EmailAccountStatus::ACTIVE, $account->getStatus());
        self::assertSame(SyncRunStatus::COMPLETED, $account->getLastSyncStatus());
        self::assertNotNull($account->getLastSyncAt());
        self::assertNull($account->getLastSyncError());
    }

    public function testAFailedSyncIsRecordedAndRethrown(): void
    {
        $this->imap
            ->method('fetchHeaders')
            ->willThrowException(new ImapConnectionException('No se pudo conectar con el servidor IMAP.'));

        $account = $this->account();

        try {
            ($this->sync())($account);
            self::fail('La sincronización debía propagar el fallo.');
        } catch (ImapConnectionException) {
            // Esperado: el comando decide qué hacer con el error.
        }

        self::assertSame(EmailAccountStatus::ERROR, $account->getStatus());
        self::assertSame(SyncRunStatus::FAILED, $account->getLastSyncStatus());
        self::assertSame('No se pudo conectar con el servidor IMAP.', $account->getLastSyncError());
        self::assertContains(AuditAction::EMAIL_SYNC_FINISHED, $this->audited);
    }

    public function testTheRunIsPersistedBeforeAndAfterTheSync(): void
    {
        $this->imapReturns([]);

        $savedRuns = [];
        $this->runs
            ->method('save')
            ->willReturnCallback(static function (EmailSyncRun $run) use (&$savedRuns): void {
                $savedRuns[] = $run;
            });

        ($this->sync())($this->account());

        self::assertCount(2, $savedRuns);
        self::assertSame($savedRuns[0], $savedRuns[1]);
        self::assertSame(SyncRunStatus::COMPLETED, $savedRuns[1]->getStatus());
    }

    public function testTheSyncIsAuditedAtBothEnds(): void
    {
        $this->imapReturns([]);

        ($this->sync())($this->account());

        self::assertSame([AuditAction::EMAIL_SYNC_STARTED, AuditAction::EMAIL_SYNC_FINISHED], $this->audited);
    }

    public function testTheTenantContextIsRestoredAfterTheSync(): void
    {
        $this->imapReturns([]);

        $tenantContext = new TenantContext();

        $this->sync($tenantContext)($this->account());

        self::assertNull($tenantContext->getOrganizationId());
    }

    /**
     * La primera pasada no tiene UID del que partir, así que pide por fecha.
     * La segunda ya sabe hasta dónde leyó y pide solo lo que ha llegado: es la
     * diferencia entre analizar un buzón entero cada vez y analizar lo nuevo.
     */
    public function testTheSecondSyncAsksOnlyForWhatArrivedAfterTheLastSeenUid(): void
    {
        $this->imapReturns([$this->header(10), $this->header(11)]);

        $account = $this->account();
        ($this->sync())($account);

        $this->imapReturnsIncremental([$this->header(12)]);
        $this->imap->expects(self::never())->method('fetchHeaders');

        $run = ($this->sync())($account);

        self::assertSame(
            [['folder' => 'INBOX', 'afterUid' => 11, 'limit' => 200]],
            $this->incrementalCalls,
        );
        self::assertSame(1, $run->getMessagesProcessed());
        self::assertSame(12, $this->cursors->all()[0]->getLastSeenUid());
    }

    public function testTheCursorIsPersistedWithTheFolderAndTheUidValidity(): void
    {
        $this->imapReturns([$this->header(4)]);

        ($this->sync())($this->account());

        $cursor = $this->cursors->all()[0];

        self::assertSame('INBOX', $cursor->getFolder());
        self::assertSame(1, $cursor->getUidValidity());
        self::assertSame(4, $cursor->getLastSeenUid());
        self::assertNotNull($cursor->getLastSyncAt());
    }

    /**
     * Si el servidor renumera la carpeta, los UID guardados dejan de apuntar al
     * mismo mensaje. Seguir usándolos haría que el sistema se saltara correo
     * nuevo en silencio, que es el peor fallo posible de una sincronización
     * incremental.
     */
    public function testAChangedUidValidityResetsTheCursorAndIsAudited(): void
    {
        $this->imapReturns([$this->header(10)]);
        $account = $this->account();
        ($this->sync())($account);

        $this->audited = [];
        $this->uidValidity = 99;
        // Tras el reinicio el cursor vuelve a ser inicial, así que la lectura
        // vuelve a ser por ventana y no por UID.
        $this->imapReturns([$this->header(1)]);

        ($this->sync())($account);

        $cursor = $this->cursors->all()[0];

        self::assertSame(99, $cursor->getUidValidity());
        self::assertSame(1, $cursor->getLastSeenUid());
        self::assertContains(AuditAction::EMAIL_SYNC_CURSOR_RESET, $this->audited);
    }

    /**
     * La primera toma de contacto con una carpeta no es un reinicio: el cursor
     * todavía no sabía nada. Auditarlo llenaría el registro de ruido.
     */
    public function testTheFirstContactWithAFolderIsNotAuditedAsAReset(): void
    {
        $this->imapReturns([$this->header(1)]);

        ($this->sync())($this->account());

        self::assertNotContains(AuditAction::EMAIL_SYNC_CURSOR_RESET, $this->audited);
    }

    /**
     * Un buzón con más correo del que cabe en una pasada no se analiza de
     * golpe: se recuerda por dónde iba la lectura hacia atrás y cada pasada
     * avanza un tramo más.
     */
    public function testAFullWindowLeavesABackfillCursorForTheNextPass(): void
    {
        $this->imapReturns([$this->header(100), $this->header(101)]);
        $this->imapReturnsBackfill([$this->header(98), $this->header(99)]);

        ($this->sync())($this->account(), limit: 2);

        $cursor = $this->cursors->all()[0];

        self::assertTrue($cursor->hasBackfillPending());
        self::assertSame(98, $cursor->getBackfillCursor());
    }

    public function testTheBackfillStartsFromTheLowestUidOfTheFirstWindow(): void
    {
        $this->imapReturns([$this->header(100), $this->header(101)]);
        $this->imapReturnsBackfill([$this->header(98), $this->header(99)]);

        ($this->sync())($this->account(), limit: 2);

        self::assertSame(
            [['folder' => 'INBOX', 'beforeUid' => 100, 'limit' => 2]],
            $this->backfillCalls,
        );
    }

    public function testTheNextPassReadsBackwardsFromTheBackfillCursor(): void
    {
        $this->imapReturns([$this->header(100), $this->header(101)]);
        $this->imapReturnsBackfill([$this->header(98), $this->header(99)]);
        $account = $this->account();
        ($this->sync())($account, limit: 2);

        $this->backfillCalls = [];
        $this->imapReturnsBackfill([$this->header(96), $this->header(97)]);

        $run = ($this->sync())($account, limit: 2);

        self::assertSame(
            [['folder' => 'INBOX', 'beforeUid' => 98, 'limit' => 2]],
            $this->backfillCalls,
        );
        self::assertSame(2, $run->getMessagesBackfilled());
        self::assertSame(96, $this->cursors->all()[0]->getBackfillCursor());
    }

    public function testAShortBackfillBatchEndsTheBackfill(): void
    {
        $this->imapReturns([$this->header(100), $this->header(101)]);
        $this->imapReturnsBackfill([$this->header(99)]);

        ($this->sync())($this->account(), limit: 2);

        self::assertFalse($this->cursors->all()[0]->hasBackfillPending());
    }

    public function testAnEmptyBackfillBatchEndsTheBackfill(): void
    {
        $this->imapReturns([$this->header(100), $this->header(101)]);

        ($this->sync())($this->account(), limit: 2);

        self::assertFalse($this->cursors->all()[0]->hasBackfillPending());
    }

    /**
     * El correo nuevo se lee **antes** que el histórico: lo que acaba de llegar
     * es lo que el usuario espera ver, y el histórico puede esperar.
     */
    public function testNewMailIsReadBeforeTheBackfill(): void
    {
        $this->imapReturns([$this->header(100), $this->header(101)]);
        $this->imapReturnsBackfill([$this->header(98), $this->header(99)]);
        $account = $this->account();
        ($this->sync())($account, limit: 2);

        $this->incrementalCalls = [];
        $this->backfillCalls = [];

        ($this->sync())($account, limit: 2);

        self::assertCount(1, $this->incrementalCalls);
        self::assertCount(1, $this->backfillCalls);
    }

    public function testTheBackfillIsCountedSeparatelyFromTheNewMail(): void
    {
        $this->imapReturns([$this->header(100), $this->header(101)]);
        $this->imapReturnsBackfill([$this->header(98), $this->header(99)]);
        $account = $this->account();
        ($this->sync())($account, limit: 2);

        $this->imapReturnsIncremental([$this->header(102)]);
        $this->imapReturnsBackfill([$this->header(96), $this->header(97)]);

        $run = ($this->sync())($account, limit: 2);

        self::assertSame(3, $run->getMessagesProcessed());
        self::assertSame(2, $run->getMessagesBackfilled());
    }
}
