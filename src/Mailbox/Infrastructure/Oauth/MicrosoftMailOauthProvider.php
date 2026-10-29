<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Oauth;

use App\Mailbox\Domain\Enum\EmailAccountProvider;

use function sprintf;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OAuth2 de Microsoft (cuentas personales) para IMAP.
 *
 * Microsoft desactivó la autenticación básica en 2024: un `LOGIN` con la
 * contraseña correcta devuelve error de credenciales. La única vía es
 * `AUTHENTICATE XOAUTH2` con un token de `https://outlook.office.com/IMAP.AccessAsUser.All`.
 *
 * El inquilino por defecto es `consumers`, que es el que corresponde a
 * outlook.com, hotmail.com y live.com. Se puede cambiar por entorno para quien
 * conecte cuentas de una organización de Microsoft 365.
 */
final class MicrosoftMailOauthProvider extends AbstractOauthMailProvider
{
    public function __construct(
        HttpClientInterface $httpClient,
        string $clientId,
        string $clientSecret,
        private readonly string $tenant = 'consumers',
    ) {
        parent::__construct($httpClient, $clientId, $clientSecret);
    }

    public function provider(): EmailAccountProvider
    {
        return EmailAccountProvider::MICROSOFT;
    }

    protected function label(): string
    {
        return 'Microsoft';
    }

    protected function authorizationEndpoint(): string
    {
        return sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/authorize', $this->tenant);
    }

    protected function tokenEndpoint(): string
    {
        return sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', $this->tenant);
    }

    protected function scopes(): array
    {
        return [
            'offline_access',
            'openid',
            'email',
            'https://outlook.office.com/IMAP.AccessAsUser.All',
        ];
    }
}
