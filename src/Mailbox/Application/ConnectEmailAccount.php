<?php

declare(strict_types=1);

namespace App\Mailbox\Application;

use App\Mailbox\Application\Dto\EmailAccountInput;
use App\Mailbox\Application\Imap\ImapClientInterface;
use App\Mailbox\Application\Imap\ImapConnectionConfig;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Conecta un buzón de correo a la organización activa.
 *
 * El orden importa: **primero se comprueba la conexión y solo después se
 * guarda**. Un buzón que no responde no debe quedar en la base de datos con
 * credenciales cifradas que no sirven para nada, y el usuario debe recibir el
 * error mientras tiene la contraseña delante (ARCHITECTURE.md §4.5).
 */
final readonly class ConnectEmailAccount
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private ImapClientInterface $imapClient,
        private CredentialCipherInterface $cipher,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    /**
     * @throws ImapConnectionException si el servidor rechaza las credenciales
     */
    public function __invoke(EmailAccountInput $input, ?Uuid $actorUserId = null): EmailAccount
    {
        $organizationId = $this->tenantContext->requireOrganizationId();

        $existing = $this->accounts->findByAddress($input->emailAddress);

        if (null !== $existing && !$existing->isDeleted()) {
            throw new InvalidArgumentException('Ese buzón ya está conectado en esta organización.');
        }

        $account = new EmailAccount(
            organizationId: $organizationId,
            emailAddress: $input->emailAddress,
            provider: $input->provider,
        );

        $account->setDisplayName($input->displayName);
        $account->configureImap(
            host: $input->imapHost,
            encryption: $input->imapEncryption,
            port: $input->imapPort,
            username: $input->imapUsername,
        );
        $account->setImapFolder($input->imapFolder);

        $config = new ImapConnectionConfig(
            host: (string) $account->getImapHost(),
            port: (int) $account->getImapPort(),
            encryption: $account->getImapEncryption() ?? $input->imapEncryption,
            username: (string) $account->getImapUsername(),
            password: $input->password,
        );

        try {
            $this->imapClient->testConnection($config);
        } catch (ImapConnectionException $e) {
            $this->auditLogger->log(
                action: AuditAction::EMAIL_ACCOUNT_CONNECTION_FAILED,
                targetType: 'email_account',
                metadata: ['address' => $account->getEmailAddress(), 'host' => $config->host],
                actorUserId: $actorUserId,
            );

            throw $e;
        }

        $account->setCredentialsEncrypted($this->cipher->encrypt($input->password));
        $account->markActive();

        $this->accounts->save($account);

        $this->auditLogger->log(
            action: AuditAction::EMAIL_ACCOUNT_CONNECTED,
            targetType: 'email_account',
            targetId: $account->getId()->toRfc4122(),
            metadata: ['address' => $account->getEmailAddress(), 'host' => $config->host],
            actorUserId: $actorUserId,
        );

        return $account;
    }
}
