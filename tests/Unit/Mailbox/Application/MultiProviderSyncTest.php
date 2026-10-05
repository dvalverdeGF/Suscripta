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
use App\Mailbox\Domain\Enum\EmailAccountStatus;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Enum\SyncRunStatus;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailSyncRunRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Mailbox\InMemoryEmailSyncCursorRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * El mismo camino genérico sirve para cualquier servidor IMAP estándar (D-23).
 *
 * Gmail, Microsoft 365, un servidor propio y el correo de un hosting no son
 * cuatro integraciones: son cuatro configuraciones de la misma. Esta prueba fija
 * esa propiedad —si algún día alguien introduce una rama por proveedor, aquí se
 * rompe— y comprueba que los parámetros que llegan al cliente son exactamente
 * los que el usuario configuró.
 */
#[CoversClass(SyncEmailAccount::class)]
final class MultiProviderSyncTest extends TestCase
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

    /** @var list<ImapConnectionConfig> */
    private array $configs = [];

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
        $this->configs = [];

        $this->messages
            ->method('save')
            ->willReturnCallback(function (EmailMessage $message): void {
                $this->saved[] = $message;
            });

        $this->cipher->method('decrypt')->willReturn('contraseña-de-aplicación');

        $this->imap->method('getUidValidity')->willReturn(1);

        $this->imap
            ->method('fetchHeaders')
            ->willReturnCallback(function (ImapConnectionConfig $config): array {
                $this->configs[] = $config;

                return [$this->header()];
            });
    }

    /**
     * @return iterable<string, array{host: string, port: int, encryption: ImapEncryption, username: string, address: string}>
     */
    public static function providers(): iterable
    {
        yield 'Gmail' => [
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => ImapEncryption::SSL,
            'username' => 'ada@gmail.com',
            'address' => 'ada@gmail.com',
        ];

        yield 'Microsoft 365' => [
            'host' => 'outlook.office365.com',
            'port' => 993,
            'encryption' => ImapEncryption::SSL,
            'username' => 'ada@miempresa.onmicrosoft.com',
            'address' => 'ada@miempresa.com',
        ];

        yield 'servidor IMAP propio' => [
            'host' => 'mail.miempresa.com',
            'port' => 143,
            'encryption' => ImapEncryption::STARTTLS,
            'username' => 'facturas',
            'address' => 'facturas@miempresa.com',
        ];

        yield 'correo de hosting' => [
            'host' => 'imap.hosting.example',
            'port' => 993,
            'encryption' => ImapEncryption::SSL,
            'username' => 'web1234',
            'address' => 'hola@miproyecto.com',
        ];
    }

    #[DataProvider('providers')]
    public function testTheSameGenericPathWorksForEveryProvider(
        string $host,
        int $port,
        ImapEncryption $encryption,
        string $username,
        string $address,
    ): void {
        $account = new EmailAccount($this->organizationId, $address);
        $account->configureImap($host, $encryption, $port, $username);
        $account->setCredentialsEncrypted('cifrado');

        $run = ($this->sync())($account);

        self::assertSame(SyncRunStatus::COMPLETED, $run->getStatus());
        self::assertSame(1, $run->getMessagesSeen());
        self::assertSame(1, $run->getMessagesProcessed());
        self::assertSame(EmailAccountStatus::ACTIVE, $account->getStatus());

        self::assertCount(1, $this->configs);

        $config = $this->configs[0];

        self::assertSame($host, $config->host);
        self::assertSame($port, $config->port);
        self::assertSame($encryption, $config->encryption);
        self::assertSame($username, $config->username);
        self::assertSame('contraseña-de-aplicación', $config->password);

        self::assertCount(1, $this->saved);
        self::assertSame($account->getId()->toRfc4122(), $this->saved[0]->getEmailAccountId()->toRfc4122());
        self::assertSame($this->organizationId->toRfc4122(), $this->saved[0]->getOrganizationId()->toRfc4122());
    }

    #[DataProvider('providers')]
    public function testThePasswordNeverAppearsInTheConnectionDescription(
        string $host,
        int $port,
        ImapEncryption $encryption,
        string $username,
        string $address,
    ): void {
        $account = new EmailAccount($this->organizationId, $address);
        $account->configureImap($host, $encryption, $port, $username);
        $account->setCredentialsEncrypted('cifrado');

        ($this->sync())($account);

        $description = $this->configs[0]->describe();

        self::assertStringNotContainsString('contraseña-de-aplicación', $description);
        self::assertStringContainsString($host, $description);
    }

    private function sync(): SyncEmailAccount
    {
        return new SyncEmailAccount(
            accounts: $this->accounts,
            messages: $this->messages,
            runs: $this->runs,
            cursors: $this->cursors,
            imapClient: $this->imap,
            cipher: $this->cipher,
            tenantContext: new TenantContext(),
            auditLogger: $this->audit,
            clock: new Clock(new MockClock(new DateTimeImmutable('2026-10-05 10:00:00'))),
        );
    }

    private function header(): ImapMessageHeader
    {
        return new ImapMessageHeader(
            uid: 1,
            messageId: '<factura@proveedor.com>',
            fromAddress: 'facturacion@proveedor.com',
            fromName: 'Proveedor',
            replyTo: null,
            toAddresses: ['yo@ovh.com'],
            subject: 'Factura',
            receivedAt: new DateTimeImmutable('2026-10-03 08:00:00'),
            sizeBytes: 2048,
            contentType: 'multipart/mixed',
            attachmentNames: ['factura.pdf'],
            attachmentTypes: ['application/pdf'],
        );
    }
}
