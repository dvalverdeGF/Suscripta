<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailbox\Application\Oauth;

use App\Mailbox\Application\ConnectEmailAccount;
use App\Mailbox\Application\CredentialCipherInterface;
use App\Mailbox\Application\Imap\ImapClientInterface;
use App\Mailbox\Application\Oauth\CompleteMailOauth;
use App\Mailbox\Application\Oauth\MailOauthProviderInterface;
use App\Mailbox\Application\Oauth\MailOauthProviderRegistry;
use App\Mailbox\Application\Oauth\MailOauthTokens;
use App\Mailbox\Application\Oauth\StartMailOauth;
use App\Mailbox\Application\UpdateEmailAccount;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Enum\ImapAuthentication;
use App\Mailbox\Domain\Exception\MailOauthException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\ValueObject\EmailAccountCredentials;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Entitlements\QuotaCheckerInterface;
use App\Shared\Application\TenantContext;

use function base64_decode;
use function base64_encode;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function sprintf;
use function substr;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

use function time;
use function urlencode;

/**
 * El flujo OAuth2 en dos pasos.
 *
 * Lo que se protege aquí es el vínculo entre la petición que empieza el flujo y
 * la que vuelve del proveedor: sin `state` y sin PKCE, cualquiera podría
 * inyectar un `code` ajeno y conectar un buzón que no es del usuario
 * (SECURITY.md §2).
 */
#[CoversClass(StartMailOauth::class)]
#[CoversClass(CompleteMailOauth::class)]
#[CoversClass(MailOauthProviderRegistry::class)]
final class MailOauthFlowTest extends TestCase
{
    private const string REDIRECT_URI = 'https://suscripta.app/mail/accounts/callback/google';

    private RequestStack $requestStack;
    private FakeMailOauthProvider $provider;
    private EmailAccountRepositoryInterface&MockObject $accounts;
    private CredentialCipherInterface $cipher;

    /** Buzón que el repositorio «encuentra» por dirección, si el test lo pone. */
    private ?EmailAccount $existing = null;

    protected function setUp(): void
    {
        $this->requestStack = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        $this->provider = new FakeMailOauthProvider(EmailAccountProvider::GMAIL);

        $this->accounts = $this->createMock(EmailAccountRepositoryInterface::class);
        // Se resuelve en cada llamada y no se vuelve a configurar después: dos
        // `method()` sobre el mismo método dejan el primer doble mandando.
        $this->accounts->method('findByAddress')->willReturnCallback(fn (): ?EmailAccount => $this->existing);

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
    }

    public function testTheAuthorizationUrlIsTheProvidersAndCarriesStateAndPkce(): void
    {
        $url = ($this->start())(EmailAccountProvider::GMAIL, self::REDIRECT_URI);

        self::assertStringContainsString('state=', $url);
        self::assertStringContainsString('code_challenge=', $url);
        self::assertStringContainsString('code_challenge_method=S256', $url);
        self::assertSame(EmailAccountProvider::GMAIL, $this->provider->lastProvider);
    }

    public function testStartingTwiceReplacesThePreviousFlow(): void
    {
        ($this->start())(EmailAccountProvider::GMAIL, self::REDIRECT_URI);
        $first = $this->requestStack->getSession()->get(StartMailOauth::SESSION_KEY);

        ($this->start())(EmailAccountProvider::GMAIL, self::REDIRECT_URI);
        $second = $this->requestStack->getSession()->get(StartMailOauth::SESSION_KEY);

        // Una sola autorización en curso: la segunda sustituye a la primera y el
        // `state` viejo deja de valer, que es lo que se espera.
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertNotSame($first['state'], $second['state']);
    }

    public function testAnUnconfiguredProviderIsRejectedBeforeRedirecting(): void
    {
        $this->provider->configured = false;

        $this->expectException(MailOauthException::class);
        $this->expectExceptionMessageMatches('/faltan las credenciales/');

        ($this->start())(EmailAccountProvider::GMAIL, self::REDIRECT_URI);
    }

    public function testAProviderWithoutAdapterIsRejected(): void
    {
        $this->expectException(MailOauthException::class);
        $this->expectExceptionMessageMatches('/Yahoo no se puede conectar con OAuth/');

        ($this->start())(EmailAccountProvider::YAHOO, self::REDIRECT_URI);
    }

    public function testTheCallbackConnectsTheAuthorizedMailbox(): void
    {
        $state = $this->startFlow();

        $account = ($this->complete())(
            provider: EmailAccountProvider::GMAIL,
            code: 'codigo',
            state: $state,
            redirectUri: self::REDIRECT_URI,
        );

        self::assertSame('facturas@gmail.com', $account->getEmailAddress());
        self::assertSame(EmailAccountProvider::GMAIL, $account->getProvider());
        self::assertSame('imap.gmail.com', $account->getImapHost());

        // Las credenciales que se guardan son el token, no una contraseña: si
        // aquí hubiera un `password`, el buzón no volvería a conectar.
        $stored = EmailAccountCredentials::fromPlaintext($this->cipher->decrypt((string) $account->getCredentialsEncrypted()));
        self::assertSame(ImapAuthentication::OAUTH, $stored->authentication());
        self::assertSame('token-de-acceso', $stored->secret());
        self::assertSame('refresh', $stored->refreshToken());
    }

    public function testTheFlowIsConsumedSoAReplayIsRejected(): void
    {
        $state = $this->startFlow();
        ($this->complete())(EmailAccountProvider::GMAIL, 'codigo', $state, self::REDIRECT_URI);

        // Reutilizar el `state` con otro `code` es exactamente el ataque que
        // `state` existe para parar.
        $this->expectException(MailOauthException::class);
        $this->expectExceptionMessageMatches('/ha caducado o ya se ha usado/');

        ($this->complete())(EmailAccountProvider::GMAIL, 'otro-codigo', $state, self::REDIRECT_URI);
    }

    public function testAStateFromAnotherSessionIsRejected(): void
    {
        $this->startFlow();

        $this->expectException(MailOauthException::class);
        $this->expectExceptionMessageMatches('/no es válida/');

        ($this->complete())(EmailAccountProvider::GMAIL, 'codigo', 'estado-inventado', self::REDIRECT_URI);
    }

    public function testACallbackForAnotherProviderIsRejected(): void
    {
        $state = $this->startFlow();

        $this->expectException(MailOauthException::class);
        $this->expectExceptionMessageMatches('/no es de Microsoft/');

        ($this->complete())(EmailAccountProvider::MICROSOFT, 'codigo', $state, self::REDIRECT_URI);
    }

    public function testAnAbandonedFlowIsRejected(): void
    {
        $state = $this->startFlow();
        $flow = $this->requestStack->getSession()->get(StartMailOauth::SESSION_KEY);
        self::assertIsArray($flow);

        $flow['expires_at'] = time() - 1;
        $this->requestStack->getSession()->set(StartMailOauth::SESSION_KEY, $flow);

        $this->expectException(MailOauthException::class);
        $this->expectExceptionMessageMatches('/ha tardado demasiado/');

        ($this->complete())(EmailAccountProvider::GMAIL, 'codigo', $state, self::REDIRECT_URI);
    }

    public function testATokenWithoutAddressCannotBecomeAMailbox(): void
    {
        $state = $this->startFlow();
        $this->provider->tokens = new MailOauthTokens(
            accessToken: 'token-de-acceso',
            refreshToken: 'refresh',
            expiresAt: new DateTimeImmutable('2026-10-05 11:00:00'),
            emailAddress: null,
        );

        $this->expectException(MailOauthException::class);
        $this->expectExceptionMessageMatches('/No hemos podido leer la dirección/');

        ($this->complete())(EmailAccountProvider::GMAIL, 'codigo', $state, self::REDIRECT_URI);
    }

    public function testAnAlreadyConnectedMailboxIsReauthorisedInsteadOfDuplicated(): void
    {
        $this->existing = new EmailAccount(Uuid::v7(), 'facturas@gmail.com', EmailAccountProvider::GMAIL);

        $state = $this->startFlow();

        $account = ($this->complete())(
            provider: EmailAccountProvider::GMAIL,
            code: 'codigo',
            state: $state,
            redirectUri: self::REDIRECT_URI,
        );

        // Es la salida cuando el usuario revoca el acceso desde su cuenta: se
        // vuelve a autorizar el mismo buzón en vez de fallar por duplicado.
        self::assertSame($this->existing, $account);
    }

    private function startFlow(): string
    {
        ($this->start())(EmailAccountProvider::GMAIL, self::REDIRECT_URI);

        $flow = $this->requestStack->getSession()->get(StartMailOauth::SESSION_KEY);
        self::assertIsArray($flow);
        self::assertIsString($flow['state']);

        return $flow['state'];
    }

    private function start(): StartMailOauth
    {
        return new StartMailOauth($this->registry(), $this->requestStack);
    }

    private function complete(): CompleteMailOauth
    {
        $tenant = new TenantContext();
        $tenant->setOrganizationId(Uuid::v7());

        $imap = $this->createMock(ImapClientInterface::class);
        $audit = $this->createMock(AuditLoggerInterface::class);
        $quotas = $this->createMock(QuotaCheckerInterface::class);

        return new CompleteMailOauth(
            providers: $this->registry(),
            requestStack: $this->requestStack,
            connectEmailAccount: new ConnectEmailAccount($this->accounts, $imap, $this->cipher, $tenant, $audit, $quotas),
            updateEmailAccount: new UpdateEmailAccount($this->accounts, $imap, $this->cipher, $audit),
            accounts: $this->accounts,
        );
    }

    private function registry(): MailOauthProviderRegistry
    {
        return new MailOauthProviderRegistry([$this->provider]);
    }
}

/**
 * Adaptador de OAuth2 controlable.
 *
 * Devuelve lo que el test le diga y recuerda a quién se le pidió: lo que hay
 * que comprobar es el flujo de la aplicación, no la llamada a Google.
 */
final class FakeMailOauthProvider implements MailOauthProviderInterface
{
    public bool $configured = true;
    public ?MailOauthTokens $tokens = null;
    public ?EmailAccountProvider $lastProvider = null;

    public function __construct(private readonly EmailAccountProvider $provider)
    {
    }

    public function provider(): EmailAccountProvider
    {
        return $this->provider;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function authorizationUrl(string $redirectUri, string $state, string $codeChallenge): string
    {
        $this->lastProvider = $this->provider;

        return sprintf(
            'https://accounts.example.test/authorize?state=%s&code_challenge=%s&code_challenge_method=S256&redirect_uri=%s',
            $state,
            $codeChallenge,
            urlencode($redirectUri),
        );
    }

    public function exchangeCode(string $code, string $redirectUri, string $codeVerifier): MailOauthTokens
    {
        return $this->tokens ??= new MailOauthTokens(
            accessToken: 'token-de-acceso',
            refreshToken: 'refresh',
            expiresAt: new DateTimeImmutable('2026-10-05 11:00:00'),
            emailAddress: 'Facturas@Gmail.com',
        );
    }

    public function refresh(string $refreshToken): MailOauthTokens
    {
        return $this->exchangeCode('', '', '');
    }
}
