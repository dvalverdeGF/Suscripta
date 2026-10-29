<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Oauth;

use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Exception\MailOauthException;

use function base64_encode;
use function bin2hex;
use function hash;
use function random_bytes;
use function rtrim;
use function sprintf;
use function strtr;

use Symfony\Component\HttpFoundation\RequestStack;

use function time;

/**
 * Primer paso del flujo OAuth2: preparar la autorización y mandar al usuario.
 *
 * El `state` y el `code_verifier` (PKCE) viven en la sesión y **solo** en la
 * sesión: son el vínculo entre la petición que empieza el flujo y la que vuelve
 * del proveedor. Sin ellos, cualquiera podría inyectar un `code` ajeno en el
 * callback y conectar un buzón que no es del usuario (SECURITY.md §2).
 */
final readonly class StartMailOauth
{
    /**
     * Clave de sesión donde vive el flujo en curso. Es una sola: empezar dos
     * autorizaciones a la vez sustituye la anterior, que es lo que se espera.
     */
    public const string SESSION_KEY = 'mail_oauth_flow';

    /** Cuánto vive el flujo antes de considerarlo abandonado. */
    public const int TTL_SECONDS = 600;

    public function __construct(
        private MailOauthProviderRegistry $providers,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @throws MailOauthException si el proveedor no está configurado
     */
    public function __invoke(EmailAccountProvider $provider, string $redirectUri): string
    {
        $adapter = $this->providers->for($provider);

        if (!$adapter->isConfigured()) {
            throw new MailOauthException(sprintf('La conexión con %s no está disponible: faltan las credenciales de la aplicación en el servidor.', $provider->label()));
        }

        $state = bin2hex(random_bytes(32));
        $verifier = $this->codeVerifier();

        $this->requestStack->getSession()->set(self::SESSION_KEY, [
            'provider' => $provider->value,
            'state' => $state,
            'verifier' => $verifier,
            'expires_at' => time() + self::TTL_SECONDS,
        ]);

        return $adapter->authorizationUrl($redirectUri, $state, $this->codeChallenge($verifier));
    }

    /**
     * `code_verifier` de PKCE: 32 bytes aleatorios en base64url (43 caracteres,
     * dentro del rango que exige la RFC 7636).
     */
    private function codeVerifier(): string
    {
        return $this->base64Url(random_bytes(32));
    }

    private function codeChallenge(string $verifier): string
    {
        return $this->base64Url(hash('sha256', $verifier, true));
    }

    private function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
