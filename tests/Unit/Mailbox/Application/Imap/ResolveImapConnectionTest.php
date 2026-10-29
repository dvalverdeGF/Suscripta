<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailbox\Application\Imap;

use App\Mailbox\Application\CredentialCipherInterface;
use App\Mailbox\Application\Imap\ImapConnectionConfig;
use App\Mailbox\Application\Imap\ResolveImapConnection;
use App\Mailbox\Application\Oauth\MailOauthProviderInterface;
use App\Mailbox\Application\Oauth\MailOauthProviderRegistry;
use App\Mailbox\Application\Oauth\MailOauthTokens;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Enum\ImapAuthentication;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Exception\MailOauthException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\ValueObject\EmailAccountCredentials;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * La renovación del token es el punto donde un fallo se paga dos veces: si no
 * se guarda el token nuevo, cada sincronización gasta una renovación; si se
 * devuelve sin renovar, el buzón deja de sincronizar en silencio.
 */
#[CoversClass(ResolveImapConnection::class)]
#[CoversClass(ImapConnectionConfig::class)]
final class ResolveImapConnectionTest extends TestCase
{
    private const string NOW = '2026-10-05 10:00:00';

    private CredentialCipherInterface $cipher;
    private EmailAccountRepositoryInterface&MockObject $accounts;

    protected function setUp(): void
    {
        $this->cipher = new class implements CredentialCipherInterface {
            public function encrypt(string $plaintext): string
            {
                return 'enc:'.base64_encode($plaintext);
            }

            public function decrypt(string $payload): string
            {
                $decoded = base64_decode(substr($payload, 4), true);

                return false === $decoded ? '' : $decoded;
            }
        };

        $this->accounts = $this->createMock(EmailAccountRepositoryInterface::class);
    }

    public function testAPasswordAccountNeedsNoRenewal(): void
    {
        $account = $this->account(EmailAccountCredentials::password('secreto'));

        $config = ($this->resolver())($account);

        self::assertSame('imap.gmail.com', $config->host);
        self::assertSame(993, $config->port);
        self::assertSame(ImapEncryption::SSL, $config->encryption);
        self::assertSame('facturas@gmail.com', $config->username);
        self::assertSame('secreto', $config->password);
        self::assertSame(ImapAuthentication::PASSWORD, $config->authentication);
        $this->accounts->expects(self::never())->method('save');
    }

    public function testAValidTokenIsUsedAsItIs(): void
    {
        $account = $this->account(
            EmailAccountCredentials::oauth('token-bueno', 'refresh', new DateTimeImmutable('2026-10-05 12:00:00')),
            EmailAccountProvider::MICROSOFT,
        );

        $config = ($this->resolver())($account);

        self::assertSame('token-bueno', $config->password);
        self::assertSame(ImapAuthentication::OAUTH, $config->authentication);
        self::assertSame('outlook.office365.com', $config->host);
        $this->accounts->expects(self::never())->method('save');
    }

    public function testAnExpiredTokenIsRenewedAndPersistedBeforeReturning(): void
    {
        $account = $this->account(
            EmailAccountCredentials::oauth('token-viejo', 'refresh-viejo', new DateTimeImmutable('2026-10-05 09:00:00')),
            EmailAccountProvider::MICROSOFT,
        );

        $saved = null;
        $this->accounts->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (EmailAccount $savedAccount) use (&$saved): void {
                $saved = $savedAccount;
            });

        $config = ($this->resolver(
            tokens: new MailOauthTokens(
                accessToken: 'token-nuevo',
                refreshToken: null,
                expiresAt: new DateTimeImmutable('2026-10-05 11:00:00'),
            ),
        ))($account);

        self::assertSame('token-nuevo', $config->password);

        // El guardado tiene que ocurrir: si no, la pasada siguiente vuelve a
        // renovar y el proveedor acaba limitando las renovaciones.
        self::assertNotNull($saved);

        $persisted = EmailAccountCredentials::fromPlaintext($this->cipher->decrypt((string) $saved->getCredentialsEncrypted()));
        self::assertSame('token-nuevo', $persisted->secret());
        self::assertSame('refresh-viejo', $persisted->refreshToken());
    }

    public function testAnExpiredTokenWithoutRefreshTokenAsksToReconnect(): void
    {
        $account = $this->account(
            EmailAccountCredentials::oauth('token-viejo', null, new DateTimeImmutable('2026-10-05 09:00:00')),
            EmailAccountProvider::MICROSOFT,
        );

        $this->accounts->expects(self::never())->method('save');

        $this->expectException(ImapConnectionException::class);
        $this->expectExceptionMessageMatches('/Vuelve a conectarlo/');

        ($this->resolver())($account);
    }

    public function testAProviderFailureIsReportedAsAConnectionFailure(): void
    {
        $account = $this->account(
            EmailAccountCredentials::oauth('token-viejo', 'refresh', new DateTimeImmutable('2026-10-05 09:00:00')),
            EmailAccountProvider::MICROSOFT,
        );

        $this->accounts->expects(self::never())->method('save');

        try {
            ($this->resolver(failure: new MailOauthException('La autorización ha sido revocada.')))($account);
            self::fail('Debería haber lanzado una excepción.');
        } catch (ImapConnectionException $e) {
            self::assertSame('La autorización ha sido revocada.', $e->getMessage());
            self::assertInstanceOf(MailOauthException::class, $e->getPrevious());
        }
    }

    private function resolver(
        ?MailOauthTokens $tokens = null,
        ?MailOauthException $failure = null,
    ): ResolveImapConnection {
        $provider = new class($tokens, $failure) implements MailOauthProviderInterface {
            public function __construct(
                private readonly ?MailOauthTokens $tokens,
                private readonly ?MailOauthException $failure,
            ) {
            }

            public function provider(): EmailAccountProvider
            {
                return EmailAccountProvider::MICROSOFT;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function authorizationUrl(string $redirectUri, string $state, string $codeChallenge): string
            {
                return 'https://example.test/authorize';
            }

            public function exchangeCode(string $code, string $redirectUri, string $codeVerifier): MailOauthTokens
            {
                throw new MailOauthException('No se canjea ningún código en este test.');
            }

            public function refresh(string $refreshToken): MailOauthTokens
            {
                if (null !== $this->failure) {
                    throw $this->failure;
                }

                return $this->tokens ?? throw new MailOauthException('Sin tokens de prueba.');
            }
        };

        return new ResolveImapConnection(
            cipher: $this->cipher,
            accounts: $this->accounts,
            oauthProviders: new MailOauthProviderRegistry([$provider]),
            clock: new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );
    }

    private function account(
        EmailAccountCredentials $credentials,
        EmailAccountProvider $provider = EmailAccountProvider::GMAIL,
    ): EmailAccount {
        $preset = $provider->preset() ?? self::fail('El proveedor de prueba necesita preset.');

        $account = new EmailAccount(Uuid::v7(), 'facturas@gmail.com', $provider);
        $account->configureImap($preset->host, $preset->encryption, $preset->port);
        $account->setCredentialsEncrypted($this->cipher->encrypt($credentials->toPlaintext()));

        return $account;
    }
}
