<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Oauth;

use App\Mailbox\Application\Oauth\MailOauthProviderInterface;
use App\Mailbox\Application\Oauth\MailOauthTokens;
use App\Mailbox\Domain\Exception\MailOauthException;

use function base64_decode;
use function count;

use DateTimeImmutable;

use function is_array;
use function is_string;
use function json_decode;
use function mb_substr;

use const PHP_QUERY_RFC3986;

use function sprintf;
use function strtr;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Base común de los proveedores de OAuth2 para IMAP.
 *
 * Google y Microsoft hablan el mismo dialecto: `authorization_code` con PKCE,
 * `refresh_token`, respuesta JSON con `access_token` y `expires_in`, y un
 * `id_token` JWT con la dirección de la cuenta. Lo único que cambia son las
 * URLs y los permisos, así que eso es lo que declara cada adaptador y el resto
 * —canje, renovación, lectura de errores— se escribe una sola vez.
 *
 * ## El `id_token` no se verifica
 *
 * No hace falta: llega directamente del endpoint de tokens por TLS, en la misma
 * respuesta que el `access_token`. Verificar la firma protegería de un emisor
 * falso, y el emisor aquí es el servidor al que nos hemos conectado nosotros.
 * Lo que sí se comprueba es que el `access_token` venga en la respuesta.
 */
abstract class AbstractOauthMailProvider implements MailOauthProviderInterface
{
    /** Un proveedor que no contesta en 15 s no va a contestar. */
    private const int TIMEOUT_SECONDS = 15;

    public function __construct(
        protected HttpClientInterface $httpClient,
        protected string $clientId,
        protected string $clientSecret,
    ) {
    }

    /**
     * Nombre del proveedor para los mensajes de error.
     */
    abstract protected function label(): string;

    abstract protected function authorizationEndpoint(): string;

    abstract protected function tokenEndpoint(): string;

    /**
     * @return list<string>
     */
    abstract protected function scopes(): array;

    /**
     * Parámetros extra de la URL de autorización.
     *
     * Google necesita `access_type=offline` y `prompt=consent` para devolver un
     * `refresh_token`; sin ellos la cuenta se conectaría y dejaría de
     * sincronizar en una hora.
     *
     * @return array<string, string>
     */
    protected function authorizationParameters(): array
    {
        return [];
    }

    public function isConfigured(): bool
    {
        return '' !== $this->clientId && '' !== $this->clientSecret;
    }

    public function authorizationUrl(string $redirectUri, string $state, string $codeChallenge): string
    {
        $query = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ] + $this->authorizationParameters();

        return $this->authorizationEndpoint().'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri, string $codeVerifier): MailOauthTokens
    {
        return $this->requestTokens([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
    }

    public function refresh(string $refreshToken): MailOauthTokens
    {
        return $this->requestTokens([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * @param array<string, string> $payload
     *
     * @throws MailOauthException
     */
    private function requestTokens(array $payload): MailOauthTokens
    {
        $payload['client_id'] = $this->clientId;
        $payload['client_secret'] = $this->clientSecret;

        try {
            $response = $this->httpClient->request('POST', $this->tokenEndpoint(), [
                'headers' => ['Accept' => 'application/json'],
                'body' => $payload,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $data = $response->toArray(false);
        } catch (HttpClientExceptionInterface $exception) {
            throw new MailOauthException(sprintf('No hemos podido hablar con %s. Inténtalo de nuevo en unos minutos.', $this->label()), 0, $exception);
        }

        $error = $data['error'] ?? null;

        if (is_string($error) && '' !== $error) {
            throw new MailOauthException($this->describeError($error, $data['error_description'] ?? null));
        }

        $accessToken = $data['access_token'] ?? null;

        if (!is_string($accessToken) || '' === $accessToken) {
            throw new MailOauthException(sprintf('%s no ha devuelto un token de acceso válido.', $this->label()));
        }

        $refreshToken = $data['refresh_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        return new MailOauthTokens(
            accessToken: $accessToken,
            refreshToken: is_string($refreshToken) && '' !== $refreshToken ? $refreshToken : null,
            // Sin `expires_in` se asume una hora, que es lo que dan los dos
            // proveedores. Renovar de más es inocuo; de menos, un fallo.
            expiresAt: new DateTimeImmutable(sprintf('+%d seconds', is_numeric($expiresIn) ? (int) $expiresIn : 3600)),
            emailAddress: $this->emailFromIdToken($data['id_token'] ?? null),
        );
    }

    /**
     * Mensaje accionable a partir del error del proveedor.
     *
     * Los errores de OAuth son opacos a propósito (`invalid_grant`), así que se
     * traducen a lo que el usuario puede hacer. La descripción original se
     * añade recortada: ayuda a diagnosticar sin llenar la pantalla.
     */
    private function describeError(string $error, mixed $description): string
    {
        $hint = match ($error) {
            'invalid_grant' => 'La autorización ha caducado o ya se ha usado. Vuelve a empezar.',
            'access_denied' => 'Has cancelado la autorización.',
            'invalid_client', 'unauthorized_client' => sprintf(
                'La aplicación no está autorizada en %s. Revisa la configuración del servidor.',
                $this->label(),
            ),
            'insufficient_scope', 'invalid_scope' => 'La cuenta no ha concedido permiso para leer el correo.',
            default => sprintf('%s ha rechazado la autorización (%s).', $this->label(), $error),
        };

        if (is_string($description) && '' !== $description) {
            return $hint.' '.mb_substr($description, 0, 200);
        }

        return $hint;
    }

    /**
     * Dirección de la cuenta autorizada, leída del `id_token`.
     *
     * Google pone `email`; Microsoft, `preferred_username` (o `upn` en cuentas
     * de trabajo). Se devuelve `null` si no hay `id_token`: el caso de uso lo
     * trata como un fallo, porque sin dirección no hay buzón que crear.
     */
    private function emailFromIdToken(mixed $idToken): ?string
    {
        if (!is_string($idToken) || '' === $idToken) {
            return null;
        }

        $parts = explode('.', $idToken);

        if (3 !== count($parts)) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);

        if (false === $payload) {
            return null;
        }

        $claims = json_decode($payload, true);

        if (!is_array($claims)) {
            return null;
        }

        foreach (['email', 'preferred_username', 'upn'] as $claim) {
            $value = $claims[$claim] ?? null;

            if (is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }
}
