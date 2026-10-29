<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailbox\Domain\ValueObject;

use App\Mailbox\Domain\Enum\ImapAuthentication;
use App\Mailbox\Domain\ValueObject\EmailAccountCredentials;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El sobre de credenciales es lo que permite que un buzón guardado antes de
 * este cambio siga funcionando: si la lectura del formato antiguo fallara, la
 * actualización dejaría a todo el mundo sin correo hasta reconectar a mano.
 */
#[CoversClass(EmailAccountCredentials::class)]
final class EmailAccountCredentialsTest extends TestCase
{
    public function testAPlainTextValueIsReadAsAPassword(): void
    {
        $credentials = EmailAccountCredentials::fromPlaintext('contraseña-de-aplicación');

        self::assertSame(ImapAuthentication::PASSWORD, $credentials->authentication());
        self::assertFalse($credentials->isOauth());
        self::assertSame('contraseña-de-aplicación', $credentials->secret());
        self::assertNull($credentials->refreshToken());
        self::assertNull($credentials->expiresAt());
    }

    public function testAJsonValueThatIsNotAnEnvelopeIsReadAsAPassword(): void
    {
        // Un JSON válido pero sin la forma esperada no puede hacer que el buzón
        // se quede sin credenciales: se trata como la contraseña que era.
        $credentials = EmailAccountCredentials::fromPlaintext('{"otra":"cosa"}');

        self::assertSame(ImapAuthentication::PASSWORD, $credentials->authentication());
        self::assertSame('{"otra":"cosa"}', $credentials->secret());
    }

    public function testAPasswordSurvivesTheRoundTrip(): void
    {
        $original = EmailAccountCredentials::password('secreto');
        $recovered = EmailAccountCredentials::fromPlaintext($original->toPlaintext());

        self::assertSame(ImapAuthentication::PASSWORD, $recovered->authentication());
        self::assertSame('secreto', $recovered->secret());
    }

    public function testOauthTokensSurviveTheRoundTrip(): void
    {
        $expiresAt = new DateTimeImmutable('2026-10-05 11:00:00');

        $original = EmailAccountCredentials::oauth('access-1', 'refresh-1', $expiresAt);
        $recovered = EmailAccountCredentials::fromPlaintext($original->toPlaintext());

        self::assertTrue($recovered->isOauth());
        self::assertSame('access-1', $recovered->secret());
        self::assertSame('refresh-1', $recovered->refreshToken());
        self::assertSame($expiresAt->getTimestamp(), $recovered->expiresAt()?->getTimestamp());
    }

    public function testAnOauthEnvelopeWithoutAccessTokenIsReadAsAPassword(): void
    {
        $credentials = EmailAccountCredentials::fromPlaintext('{"type":"oauth","expires_at":"2026-10-05T11:00:00+00:00"}');

        self::assertFalse($credentials->isOauth());
    }

    public function testAPasswordNeverNeedsARefresh(): void
    {
        $credentials = EmailAccountCredentials::password('secreto');

        self::assertFalse($credentials->needsRefresh(new DateTimeImmutable('+10 years')));
    }

    public function testATokenIsRefreshedBeforeItExpires(): void
    {
        $expiresAt = new DateTimeImmutable('2026-10-05 11:00:00');
        $credentials = EmailAccountCredentials::oauth('access-1', 'refresh-1', $expiresAt);

        // Un minuto antes de caducar ya se renueva: si no, la conexión puede
        // empezar bien y caerse a mitad de una carpeta grande.
        self::assertTrue($credentials->needsRefresh($expiresAt->modify('-1 minute')));
        self::assertFalse($credentials->needsRefresh($expiresAt->modify('-10 minutes')));
    }

    public function testRefreshingKeepsThePreviousRefreshTokenWhenTheProviderOmitsIt(): void
    {
        $credentials = EmailAccountCredentials::oauth('access-1', 'refresh-1', new DateTimeImmutable('2026-10-05 11:00:00'));

        $refreshed = $credentials->withRefreshedTokens(
            accessToken: 'access-2',
            refreshToken: null,
            expiresAt: new DateTimeImmutable('2026-10-05 12:00:00'),
        );

        // Perder el `refresh_token` obligaría al usuario a volver a autorizar
        // la cuenta cada hora.
        self::assertSame('refresh-1', $refreshed->refreshToken());
        self::assertSame('access-2', $refreshed->secret());
    }

    public function testDescribeNeverRevealsTheSecret(): void
    {
        $password = EmailAccountCredentials::password('contraseña-secreta');
        $oauth = EmailAccountCredentials::oauth('token-secreto', 'refresh-secreto', new DateTimeImmutable('2026-10-05 11:00:00'));

        self::assertSame('contraseña', $password->describe());
        self::assertStringNotContainsString('secreto', $oauth->describe());
        self::assertStringContainsString('OAuth2', $oauth->describe());
    }
}
