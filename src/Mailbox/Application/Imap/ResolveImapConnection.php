<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Imap;

use App\Mailbox\Application\CredentialCipherInterface;
use App\Mailbox\Application\Oauth\MailOauthProviderRegistry;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Exception\MailOauthException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\ValueObject\EmailAccountCredentials;
use App\Shared\Application\Clock;

/**
 * Traduce un buzón guardado a los parámetros con los que se abre su conexión.
 *
 * ## Por qué existe
 *
 * Descifrar las credenciales y montar el `ImapConnectionConfig` estaba copiado
 * en cuatro servicios (conectar, actualizar, probar y sincronizar). Con una
 * sola forma de autenticación daba igual; con dos, cada copia que se olvidara
 * del token sería un buzón que funciona al conectarlo y falla al sincronizar.
 *
 * ## Renovación del token
 *
 * Un token de OAuth dura una hora. Cuando caduca (o está a punto), se renueva
 * aquí y se guarda la credencial nueva **antes** de devolver la configuración:
 * si se devolviera sin guardar, la siguiente pasada volvería a renovar y cada
 * sincronización gastaría una renovación. Si la renovación falla, el buzón no
 * se puede leer y el error lo dice tal cual: hay que volver a autorizar.
 */
final readonly class ResolveImapConnection
{
    public function __construct(
        private CredentialCipherInterface $cipher,
        private EmailAccountRepositoryInterface $accounts,
        private MailOauthProviderRegistry $oauthProviders,
        private Clock $clock,
    ) {
    }

    /**
     * @throws ImapConnectionException si el token ha caducado y no se puede renovar
     */
    public function __invoke(EmailAccount $account): ImapConnectionConfig
    {
        $credentials = EmailAccountCredentials::fromPlaintext(
            $this->cipher->decrypt((string) $account->getCredentialsEncrypted()),
        );

        if ($credentials->needsRefresh($this->clock->now())) {
            $credentials = $this->refresh($account, $credentials);
        }

        return ImapConnectionConfig::forAccount($account, $credentials);
    }

    /**
     * @throws ImapConnectionException
     */
    private function refresh(EmailAccount $account, EmailAccountCredentials $credentials): EmailAccountCredentials
    {
        $refreshToken = $credentials->refreshToken();

        if (null === $refreshToken) {
            throw new ImapConnectionException('La autorización del buzón ha caducado. Vuelve a conectarlo con el botón del proveedor.');
        }

        try {
            $tokens = $this->oauthProviders->for($account->getProvider())->refresh($refreshToken);
        } catch (MailOauthException $e) {
            throw new ImapConnectionException($e->getMessage(), 0, $e);
        }

        $refreshed = $credentials->withRefreshedTokens(
            accessToken: $tokens->accessToken,
            refreshToken: $tokens->refreshToken,
            expiresAt: $tokens->expiresAt,
        );

        $account->setCredentialsEncrypted($this->cipher->encrypt($refreshed->toPlaintext()));
        $this->accounts->save($account);

        return $refreshed;
    }
}
