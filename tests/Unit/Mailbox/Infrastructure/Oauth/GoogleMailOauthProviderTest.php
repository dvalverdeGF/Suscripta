<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailbox\Infrastructure\Oauth;

use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Exception\MailOauthException;
use App\Mailbox\Infrastructure\Oauth\GoogleMailOauthProvider;
use DateTimeImmutable;

use function is_string;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_QUERY;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Se prueba contra `MockHttpClient` y no contra Google: lo que puede romperse
 * aquí es la lectura de la respuesta —el `id_token`, el `expires_in` que a veces
 * falta, los errores opacos—, y eso no se reproduce llamando de verdad.
 */
#[CoversClass(GoogleMailOauthProvider::class)]
final class GoogleMailOauthProviderTest extends TestCase
{
    private const string REDIRECT_URI = 'https://suscripta.app/mail/accounts/callback/google';

    public function testItServesGmail(): void
    {
        self::assertSame(EmailAccountProvider::GMAIL, $this->provider()->provider());
    }

    public function testWithoutCredentialsTheButtonIsHidden(): void
    {
        self::assertFalse($this->provider(clientId: '', clientSecret: '')->isConfigured());
        self::assertFalse($this->provider(clientSecret: '')->isConfigured());
        self::assertTrue($this->provider()->isConfigured());
    }

    public function testTheAuthorizationUrlAsksForOfflineAccessAndPkce(): void
    {
        $url = $this->provider()->authorizationUrl(self::REDIRECT_URI, 'estado-123', 'desafio-abc');
        $query = $this->queryOf($url);

        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);

        // Sin `access_type=offline` Google no devuelve `refresh_token` y el
        // buzón dejaría de sincronizar en una hora.
        self::assertSame('offline', $query['access_type']);
        self::assertSame('consent', $query['prompt']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('desafio-abc', $query['code_challenge']);
        self::assertSame('estado-123', $query['state']);
        self::assertSame(self::REDIRECT_URI, $query['redirect_uri']);
        self::assertSame('https://mail.google.com/ openid email', $query['scope']);
        self::assertSame('client-id', $query['client_id']);
    }

    public function testTheCodeExchangeReturnsTheTokensAndTheAccountAddress(): void
    {
        $provider = $this->provider(responses: [[
            'access_token' => 'token-nuevo',
            'refresh_token' => 'refresh-nuevo',
            'expires_in' => 3599,
            'id_token' => $this->idToken(['email' => 'Facturas@Gmail.com']),
        ]]);

        $tokens = $provider->exchangeCode('codigo', self::REDIRECT_URI, 'verificador');

        self::assertSame('token-nuevo', $tokens->accessToken);
        self::assertSame('refresh-nuevo', $tokens->refreshToken);
        self::assertSame('Facturas@Gmail.com', $tokens->emailAddress);
        self::assertEqualsWithDelta(3599, $tokens->expiresAt->getTimestamp() - new DateTimeImmutable()->getTimestamp(), 2);
    }

    public function testWithoutExpiresInAnHourIsAssumed(): void
    {
        $provider = $this->provider(responses: [['access_token' => 'token']]);

        $tokens = $provider->refresh('refresh');

        self::assertNull($tokens->refreshToken);
        self::assertEqualsWithDelta(3600, $tokens->expiresAt->getTimestamp() - new DateTimeImmutable()->getTimestamp(), 2);
    }

    public function testAnIdTokenWithoutEmailLeavesTheAddressEmpty(): void
    {
        // Sin dirección no hay buzón que crear; el caso de uso lo detecta.
        $provider = $this->provider(responses: [['access_token' => 'token', 'id_token' => 'no-es-un-jwt']]);

        self::assertNull($provider->refresh('refresh')->emailAddress);
    }

    public function testMicrosoftStyleClaimsAreUnderstoodToo(): void
    {
        $provider = $this->provider(responses: [[
            'access_token' => 'token',
            'id_token' => $this->idToken(['preferred_username' => 'facturas@outlook.com']),
        ]]);

        self::assertSame('facturas@outlook.com', $provider->refresh('refresh')->emailAddress);
    }

    public function testAUsedCodeIsExplained(): void
    {
        $provider = $this->provider(responses: [['error' => 'invalid_grant', 'error_description' => 'Bad Request']]);

        $this->expectException(MailOauthException::class);
        $this->expectExceptionMessageMatches('/ha caducado o ya se ha usado.*Bad Request/');

        $provider->exchangeCode('codigo', self::REDIRECT_URI, 'verificador');
    }

    public function testACancelledAuthorizationIsExplained(): void
    {
        $provider = $this->provider(responses: [['error' => 'access_denied']]);

        $this->expectException(MailOauthException::class);
        $this->expectExceptionMessageMatches('/Has cancelado la autorización/');

        $provider->refresh('refresh');
    }

    public function testAMisconfiguredApplicationIsExplained(): void
    {
        $provider = $this->provider(responses: [['error' => 'invalid_client']]);

        $this->expectException(MailOauthException::class);
        $this->expectExceptionMessageMatches('/no está autorizada en Google/');

        $provider->refresh('refresh');
    }

    public function testAMissingAccessTokenIsAnError(): void
    {
        $provider = $this->provider(responses: [['token_type' => 'Bearer']]);

        $this->expectException(MailOauthException::class);
        $this->expectExceptionMessageMatches('/no ha devuelto un token de acceso válido/');

        $provider->refresh('refresh');
    }

    public function testANetworkFailureIsReportedWithoutLeakingAnything(): void
    {
        $provider = new GoogleMailOauthProvider(
            new MockHttpClient(static fn (): MockResponse => throw new TransportException('Connection refused')),
            'client-id',
            'client-secret',
        );

        try {
            $provider->refresh('refresh-secreto');
            self::fail('Debería haber lanzado una excepción.');
        } catch (MailOauthException $e) {
            self::assertStringContainsString('No hemos podido hablar con Google', $e->getMessage());
            self::assertStringNotContainsString('refresh-secreto', $e->getMessage());
        }
    }

    /**
     * @param list<array<string, mixed>> $responses
     */
    private function provider(
        string $clientId = 'client-id',
        string $clientSecret = 'client-secret',
        array $responses = [],
    ): GoogleMailOauthProvider {
        $client = new MockHttpClient(array_map(
            static fn (array $body): MockResponse => new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]),
            $responses,
        ));

        return new GoogleMailOauthProvider($client, $clientId, $clientSecret);
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(string $url): array
    {
        $raw = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $raw);

        $query = [];

        foreach ($raw as $key => $value) {
            if (is_string($value)) {
                $query[(string) $key] = $value;
            }
        }

        return $query;
    }

    /**
     * @param array<string, string> $claims
     */
    private function idToken(array $claims): string
    {
        return 'eyJhbGciOiJSUzI1NiJ9.'.rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=').'.firma';
    }
}
