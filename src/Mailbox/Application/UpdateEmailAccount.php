<?php

declare(strict_types=1);

namespace App\Mailbox\Application;

use App\Mailbox\Application\Dto\EmailAccountInput;
use App\Mailbox\Application\Imap\ImapClientInterface;
use App\Mailbox\Application\Imap\ImapConnectionConfig;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Exception\MailOauthException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * Cambia la configuración de un buzón ya conectado.
 *
 * La dirección de correo no se puede cambiar: es la identidad de la cuenta y
 * de ella depende la deduplicación. Si el usuario se equivocó de dirección,
 * desconecta y vuelve a conectar.
 *
 * Las credenciales son opcionales. Si no se envían, se conservan las que ya
 * estaban cifradas y **no** se vuelve a comprobar la conexión: no hay nada
 * nuevo que comprobar (SECURITY.md §3).
 *
 * Con un proveedor de catálogo (Gmail, Microsoft, Yahoo, iCloud) se ignoran el
 * host y el puerto del formulario y mandan los del preset: si el usuario
 * pudiera apuntar un buzón de Gmail a otro servidor, el OAuth2 dejaría de
 * tener sentido (D-93).
 */
final readonly class UpdateEmailAccount
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private ImapClientInterface $imapClient,
        private CredentialCipherInterface $cipher,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    /**
     * @throws ImapConnectionException si se cambia la contraseña y el servidor la rechaza
     */
    public function __invoke(EmailAccount $account, EmailAccountInput $input, ?Uuid $actorUserId = null): EmailAccount
    {
        if (mb_strtolower(trim($input->emailAddress)) !== $account->getEmailAddress()) {
            throw new InvalidArgumentException('La dirección del buzón no se puede cambiar. Desconéctalo y conecta el nuevo.');
        }

        if ($input->provider->requiresOauth() && null !== $input->credentials && !$input->credentials->isOauth()) {
            throw new MailOauthException(sprintf('%s ya no acepta contraseñas: conecta la cuenta con su botón de Microsoft.', $input->provider->label()));
        }

        $preset = $input->provider->preset();

        $account->setDisplayName($input->displayName);
        $account->configureImap(
            host: $preset->host ?? $input->imapHost,
            encryption: $preset->encryption ?? $input->imapEncryption,
            port: $preset->port ?? $input->imapPort,
            username: $input->imapUsername,
        );
        $account->setImapFolder($input->imapFolder);
        $account->setProvider($input->provider);

        if (null !== $input->credentials) {
            $config = new ImapConnectionConfig(
                host: (string) $account->getImapHost(),
                port: (int) $account->getImapPort(),
                encryption: $account->getImapEncryption() ?? ImapEncryption::SSL,
                username: (string) $account->getImapUsername(),
                password: $input->credentials->secret(),
                authentication: $input->credentials->authentication(),
            );

            try {
                $this->imapClient->testConnection($config);
            } catch (ImapConnectionException $e) {
                $this->auditLogger->log(
                    action: AuditAction::EMAIL_ACCOUNT_CONNECTION_FAILED,
                    targetType: 'email_account',
                    targetId: $account->getId()->toRfc4122(),
                    metadata: ['address' => $account->getEmailAddress(), 'host' => $config->host],
                    actorUserId: $actorUserId,
                );

                throw $e;
            }

            $account->setCredentialsEncrypted($this->cipher->encrypt($input->credentials->toPlaintext()));
        }

        $account->markActive();
        $this->accounts->save($account);

        $this->auditLogger->log(
            action: AuditAction::EMAIL_ACCOUNT_CONNECTED,
            targetType: 'email_account',
            targetId: $account->getId()->toRfc4122(),
            metadata: ['address' => $account->getEmailAddress(), 'host' => (string) $account->getImapHost(), 'updated' => true],
            actorUserId: $actorUserId,
        );

        return $account;
    }
}
