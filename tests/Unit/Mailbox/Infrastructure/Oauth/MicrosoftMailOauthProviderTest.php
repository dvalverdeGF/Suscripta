<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailbox\Infrastructure\Oauth;

use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Infrastructure\Oauth\MicrosoftMailOauthProvider;

use function is_string;
use function parse_str;
use function parse_url;

use const PHP_URL_QUERY;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Microsoft se diferencia de Google en tres cosas que se rompen en silencio: el
 * inquilino (`consumers` para cuentas personales), el permiso de IMAP —que es
 * de `outlook.office.com` y no de `graph.microsoft.com`— y el `offline_access`
 * sin el cual no hay `refresh_token`.
 */
#[CoversClass(MicrosoftMailOauthProvider::class)]
final class MicrosoftMailOauthProviderTest extends TestCase
{
    private const string REDIRECT_URI = 'https://suscripta.app/mail/accounts/callback/microsoft';

    public function testItServesMicrosoft(): void
    {
        self::assertSame(EmailAccountProvider::MICROSOFT, $this->provider()->provider());
    }

    public function testItPointsAtThePersonalAccountsTenantByDefault(): void
    {
        // `consumers` es el inquilino de outlook.com y hotmail.com. Con
        // `common` o `organizations` las cuentas personales no autorizan.
        $url = $this->provider()->authorizationUrl(self::REDIRECT_URI, 'estado', 'desafio');

        self::assertStringStartsWith('https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize?', $url);
    }

    public function testTheTenantCanBeChangedForAnOrganisation(): void
    {
        $url = $this->provider(tenant: 'contoso.onmicrosoft.com')
            ->authorizationUrl(self::REDIRECT_URI, 'estado', 'desafio');

        self::assertStringContainsString('/contoso.onmicrosoft.com/oauth2/v2.0/authorize', $url);
    }

    public function testTheAuthorizationUrlAsksForOfflineAccessAndTheImapScope(): void
    {
        $query = $this->queryOf($this->provider()->authorizationUrl(self::REDIRECT_URI, 'estado', 'desafio'));

        // `offline_access` es lo que hace que haya `refresh_token`; el permiso
        // de IMAP es de `outlook.office.com`, no el de Microsoft Graph.
        self::assertSame(
            'offline_access openid email https://outlook.office.com/IMAP.AccessAsUser.All',
            $query['scope'],
        );
        self::assertSame('S256', $query['code_challenge_method']);
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

    private function provider(string $tenant = 'consumers'): MicrosoftMailOauthProvider
    {
        return new MicrosoftMailOauthProvider(new MockHttpClient([]), 'client-id', 'client-secret', $tenant);
    }
}
