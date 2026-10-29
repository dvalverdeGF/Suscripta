<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailbox\Application;

use App\Mailbox\Application\ConnectEmailAccount;
use App\Mailbox\Application\CredentialCipherInterface;
use App\Mailbox\Application\Dto\EmailAccountInput;
use App\Mailbox\Application\Imap\ImapClientInterface;
use App\Mailbox\Application\Imap\ImapConnectionConfig;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\ValueObject\EmailAccountCredentials;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Entitlements\QuotaCheckerInterface;
use App\Shared\Application\TenantContext;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * El catálogo de proveedores manda sobre lo que traiga la petición.
 *
 * Con un proveedor de catálogo (Gmail, Microsoft, Yahoo, iCloud) el servidor, el
 * puerto y el cifrado los pone la aplicación. Si el formulario pudiera
 * elegirlos, el catálogo no serviría de nada: bastaría con mandar otro host
 * para que las credenciales viajaran a un servidor ajeno (D-93).
 *
 * Esta prueba fija esa garantía **en el caso de uso** y no en la pantalla,
 * porque es donde tiene que valer: aunque alguien rellene el formulario a mano,
 * aquí se sigue usando el preset.
 */
#[CoversClass(ConnectEmailAccount::class)]
final class ConnectEmailAccountTest extends TestCase
{
    private EmailAccountRepositoryInterface&MockObject $accounts;

    private ImapClientInterface&MockObject $imap;

    private CredentialCipherInterface&MockObject $cipher;

    private TenantContext $tenantContext;

    private ConnectEmailAccount $connect;

    /** @var list<ImapConnectionConfig> */
    private array $connections = [];

    /** @var list<EmailAccount> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->accounts = $this->createMock(EmailAccountRepositoryInterface::class);
        $this->imap = $this->createMock(ImapClientInterface::class);
        $this->cipher = $this->createMock(CredentialCipherInterface::class);
        $this->tenantContext = new TenantContext();

        $this->connections = [];
        $this->saved = [];

        $this->imap
            ->method('testConnection')
            ->willReturnCallback(function (ImapConnectionConfig $config): void {
                $this->connections[] = $config;
            });

        $this->cipher->method('encrypt')->willReturn('cifrado');

        $this->accounts
            ->method('save')
            ->willReturnCallback(function (EmailAccount $account): void {
                $this->saved[] = $account;
            });

        $this->tenantContext->setOrganizationId(Uuid::v7());

        $this->connect = new ConnectEmailAccount(
            accounts: $this->accounts,
            imapClient: $this->imap,
            cipher: $this->cipher,
            tenantContext: $this->tenantContext,
            auditLogger: $this->createMock(AuditLoggerInterface::class),
            quotas: $this->createMock(QuotaCheckerInterface::class),
        );
    }

    /**
     * @return iterable<string, array{EmailAccountProvider, string, int}>
     */
    public static function presetProviders(): iterable
    {
        yield 'Gmail' => [EmailAccountProvider::GMAIL, 'imap.gmail.com', 993];
        yield 'Microsoft' => [EmailAccountProvider::MICROSOFT, 'outlook.office365.com', 993];
        yield 'Yahoo' => [EmailAccountProvider::YAHOO, 'imap.mail.yahoo.com', 993];
        yield 'iCloud' => [EmailAccountProvider::ICLOUD, 'imap.mail.me.com', 993];
    }

    #[DataProvider('presetProviders')]
    public function testTheCatalogWinsOverWhatTheRequestSays(
        EmailAccountProvider $provider,
        string $host,
        int $port,
    ): void {
        ($this->connect)(new EmailAccountInput(
            emailAddress: 'facturas@ejemplo.com',
            credentials: $provider->requiresOauth()
                ? EmailAccountCredentials::oauth('token-de-acceso', 'token-de-renovación', new DateTimeImmutable('+1 hour'))
                : EmailAccountCredentials::password('contraseña-de-aplicación'),
            imapHost: 'servidor-de-pruebas.example',
            imapEncryption: ImapEncryption::STARTTLS,
            imapPort: 1143,
            provider: $provider,
        ));

        $config = $this->connections[0];
        self::assertSame($host, $config->host);
        self::assertSame($port, $config->port);
        self::assertSame(ImapEncryption::SSL, $config->encryption);

        self::assertCount(1, $this->saved);
        self::assertSame($host, $this->saved[0]->getImapHost());
        self::assertSame($port, $this->saved[0]->getImapPort());
    }

    public function testTheGenericImapUsesWhatTheRequestSays(): void
    {
        // El caso contrario: sin preset no hay catálogo que imponer y el usuario
        // configura su servidor. Si esta prueba se rompiera, el IMAP genérico
        // habría dejado de servir para cualquier hosting.
        ($this->connect)(new EmailAccountInput(
            emailAddress: 'facturas@ejemplo.com',
            credentials: EmailAccountCredentials::password('contraseña-de-aplicación'),
            imapHost: 'servidor-de-pruebas.example',
            imapEncryption: ImapEncryption::STARTTLS,
            imapPort: 1143,
        ));

        $config = $this->connections[0];
        self::assertSame('servidor-de-pruebas.example', $config->host);
        self::assertSame(1143, $config->port);
        self::assertSame(ImapEncryption::STARTTLS, $config->encryption);
    }
}
