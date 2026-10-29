<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Oauth;

use App\Mailbox\Domain\Enum\EmailAccountProvider;

/**
 * OAuth2 de Google para IMAP.
 *
 * El permiso `https://mail.google.com/` es el único que da acceso IMAP completo;
 * `gmail.readonly` es de la API REST y no sirve aquí. `openid email` es lo que
 * hace que la respuesta traiga un `id_token` con la dirección de la cuenta, y
 * así el usuario no tiene que escribirla (ni puede escribir otra distinta).
 *
 * `access_type=offline` y `prompt=consent` son obligatorios: sin ellos Google
 * devuelve un token de una hora y **ningún** `refresh_token`, y el buzón dejaría
 * de sincronizar al rato.
 */
final class GoogleMailOauthProvider extends AbstractOauthMailProvider
{
    public function provider(): EmailAccountProvider
    {
        return EmailAccountProvider::GMAIL;
    }

    protected function label(): string
    {
        return 'Google';
    }

    protected function authorizationEndpoint(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function scopes(): array
    {
        return ['https://mail.google.com/', 'openid', 'email'];
    }

    protected function authorizationParameters(): array
    {
        return [
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ];
    }
}
