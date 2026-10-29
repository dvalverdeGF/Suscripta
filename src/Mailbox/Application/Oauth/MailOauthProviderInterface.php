<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Oauth;

use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Exception\MailOauthException;

/**
 * Puerto hacia un proveedor de OAuth2 que dé acceso a IMAP.
 *
 * La aplicación no sabe si detrás está Google o Microsoft: pide una URL de
 * autorización, canjea un código y renueva un token. Añadir un proveedor nuevo
 * es escribir un adaptador y etiquetarlo, no tocar los casos de uso (D-93).
 *
 * Todo el flujo usa **PKCE** además del `state`: el `code` viaja por la barra de
 * direcciones y, aunque el cliente sea confidencial, un código interceptado no
 * sirve sin el `code_verifier` que nunca sale del servidor.
 */
interface MailOauthProviderInterface
{
    /**
     * Proveedor de correo al que sirve este adaptador.
     */
    public function provider(): EmailAccountProvider;

    /**
     * ¿Están configuradas las credenciales de la aplicación (client id/secret)?
     *
     * Si no lo están, la interfaz esconde el botón en vez de ofrecer un camino
     * que termina en un error del proveedor.
     */
    public function isConfigured(): bool;

    /**
     * URL a la que se manda al usuario para que autorice el acceso.
     */
    public function authorizationUrl(string $redirectUri, string $state, string $codeChallenge): string;

    /**
     * Canjea el código de autorización por tokens.
     *
     * @throws MailOauthException
     */
    public function exchangeCode(string $code, string $redirectUri, string $codeVerifier): MailOauthTokens;

    /**
     * Renueva un token de acceso caducado sin volver a pedir permiso.
     *
     * @throws MailOauthException
     */
    public function refresh(string $refreshToken): MailOauthTokens;
}
