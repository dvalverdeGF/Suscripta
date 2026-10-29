<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\ValueObject;

use App\Mailbox\Domain\Enum\ImapAuthentication;
use DateTimeImmutable;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

use JsonException;
use SensitiveParameter;

use function sprintf;

/**
 * Lo que hace falta para abrir la sesión IMAP de un buzón, ya descifrado.
 *
 * Hay dos formas y solo dos: una **contraseña** (normal o de aplicación) o un
 * **token de OAuth2** con su `refresh_token`. Se guardan en el mismo campo
 * cifrado del buzón porque comparten ciclo de vida: se escriben al conectar,
 * se leen justo antes de abrir la conexión y se borran al desconectar
 * (SECURITY.md §3).
 *
 * ## Por qué hay un envoltorio en JSON
 *
 * El campo guardaba la contraseña en crudo. Un token de OAuth necesita además
 * su `refresh_token` y su fecha de caducidad, así que el contenido pasa a ser
 * un objeto JSON. `fromPlaintext()` acepta **también** el formato antiguo: una
 * contraseña guardada antes de este cambio se lee como lo que es, sin migración
 * ni ventana de compatibilidad. Como el texto va cifrado, la única forma de
 * distinguirlos es intentar leerlo como JSON y, si no lo es, tratarlo como
 * contraseña.
 */
final readonly class EmailAccountCredentials
{
    /**
     * Margen de renovación: un token que caduca dentro de un minuto se renueva
     * antes de usarlo. Si no, la conexión puede empezar bien y caerse a mitad
     * de la sincronización de una carpeta grande.
     */
    public const int REFRESH_SKEW_SECONDS = 120;

    private function __construct(
        public ImapAuthentication $authentication,
        #[SensitiveParameter]
        private string $secret,
        #[SensitiveParameter]
        private ?string $refreshToken = null,
        private ?DateTimeImmutable $expiresAt = null,
    ) {
    }

    public static function password(#[SensitiveParameter] string $password): self
    {
        return new self(ImapAuthentication::PASSWORD, $password);
    }

    public static function oauth(
        #[SensitiveParameter]
        string $accessToken,
        #[SensitiveParameter]
        ?string $refreshToken,
        DateTimeImmutable $expiresAt,
    ): self {
        return new self(ImapAuthentication::OAUTH, $accessToken, $refreshToken, $expiresAt);
    }

    /**
     * Reconstruye las credenciales desde el texto descifrado del buzón.
     */
    public static function fromPlaintext(#[SensitiveParameter] string $plaintext): self
    {
        try {
            $decoded = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::password($plaintext);
        }

        if (!is_array($decoded) || !is_string($decoded['type'] ?? null)) {
            return self::password($plaintext);
        }

        if ('oauth' === $decoded['type']) {
            $accessToken = $decoded['access_token'] ?? null;
            $expiresAt = $decoded['expires_at'] ?? null;

            if (!is_string($accessToken) || !is_string($expiresAt)) {
                return self::password($plaintext);
            }

            return self::oauth(
                accessToken: $accessToken,
                refreshToken: is_string($decoded['refresh_token'] ?? null) ? $decoded['refresh_token'] : null,
                expiresAt: new DateTimeImmutable($expiresAt),
            );
        }

        $secret = $decoded['secret'] ?? null;

        return is_string($secret) ? self::password($secret) : self::password($plaintext);
    }

    /**
     * Texto que se cifra y se guarda. Nunca se registra en un log.
     */
    public function toPlaintext(): string
    {
        if (ImapAuthentication::OAUTH === $this->authentication) {
            return json_encode([
                'type' => 'oauth',
                'access_token' => $this->secret,
                'refresh_token' => $this->refreshToken,
                'expires_at' => $this->expiresAt?->format('c'),
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode([
            'type' => 'password',
            'secret' => $this->secret,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * El secreto tal y como lo espera el servidor IMAP: la contraseña en
     * `LOGIN` y el token de acceso en `AUTHENTICATE XOAUTH2`.
     */
    public function secret(): string
    {
        return $this->secret;
    }

    public function authentication(): ImapAuthentication
    {
        return $this->authentication;
    }

    public function isOauth(): bool
    {
        return ImapAuthentication::OAUTH === $this->authentication;
    }

    public function refreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * ¿Hay que renovar el token antes de usarlo?
     *
     * Una contraseña no caduca nunca; un token sin fecha de caducidad tampoco
     * se renueva a ciegas (se intenta usar y, si el servidor lo rechaza, el
     * usuario vuelve a autorizar).
     */
    public function needsRefresh(DateTimeImmutable $now): bool
    {
        if (!$this->isOauth() || null === $this->expiresAt) {
            return false;
        }

        return $this->expiresAt->getTimestamp() - self::REFRESH_SKEW_SECONDS <= $now->getTimestamp();
    }

    /**
     * Credenciales nuevas tras renovar el token.
     *
     * Algunos proveedores no devuelven `refresh_token` en la renovación: en ese
     * caso se conserva el que ya teníamos, porque perderlo obligaría al usuario
     * a volver a autorizar la cuenta cada hora.
     */
    public function withRefreshedTokens(
        #[SensitiveParameter]
        string $accessToken,
        #[SensitiveParameter]
        ?string $refreshToken,
        DateTimeImmutable $expiresAt,
    ): self {
        return self::oauth($accessToken, $refreshToken ?? $this->refreshToken, $expiresAt);
    }

    /**
     * Descripción segura para logs y mensajes: qué tipo es y cuándo caduca.
     */
    public function describe(): string
    {
        if (!$this->isOauth()) {
            return 'contraseña';
        }

        return sprintf('OAuth2 (caduca %s)', $this->expiresAt?->format('c') ?? 'sin fecha');
    }
}
