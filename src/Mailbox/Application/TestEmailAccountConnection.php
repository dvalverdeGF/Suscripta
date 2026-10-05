<?php

declare(strict_types=1);

namespace App\Mailbox\Application;

use App\Mailbox\Application\Imap\ImapClientInterface;
use App\Mailbox\Application\Imap\ImapConnectionConfig;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Comprueba que un buzón ya guardado sigue siendo accesible.
 *
 * Se usa desde el botón «Probar conexión» y desde el diagnóstico cuando una
 * sincronización falla. Nunca devuelve la contraseña ni el error crudo del
 * servidor: solo un mensaje accionable (SECURITY.md §3).
 */
final readonly class TestEmailAccountConnection
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private ImapClientInterface $imapClient,
        private CredentialCipherInterface $cipher,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    /**
     * @return list<string> carpetas disponibles en el buzón
     *
     * @throws ImapConnectionException
     */
    public function __invoke(EmailAccount $account, ?Uuid $actorUserId = null): array
    {
        if (!$account->isConfigured()) {
            throw new InvalidArgumentException('La cuenta no tiene configurado el servidor IMAP o le faltan las credenciales.');
        }

        $config = new ImapConnectionConfig(
            host: (string) $account->getImapHost(),
            port: (int) $account->getImapPort(),
            encryption: $account->getImapEncryption() ?? ImapEncryption::SSL,
            username: (string) $account->getImapUsername(),
            password: $this->cipher->decrypt((string) $account->getCredentialsEncrypted()),
        );

        try {
            $folders = $this->imapClient->listFolders($config);
        } catch (ImapConnectionException $e) {
            $account->markError($e->getMessage());

            $this->auditLogger->log(
                action: AuditAction::EMAIL_ACCOUNT_CONNECTION_FAILED,
                targetType: 'email_account',
                targetId: $account->getId()->toRfc4122(),
                metadata: ['address' => $account->getEmailAddress()],
                actorUserId: $actorUserId,
            );

            throw $e;
        }

        $account->markActive();
        $this->accounts->save($account);

        return $folders;
    }
}
