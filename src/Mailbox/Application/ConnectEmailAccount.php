<?php

declare(strict_types=1);

namespace App\Mailbox\Application;

use App\Mailbox\Application\Dto\EmailAccountInput;
use App\Mailbox\Application\Imap\ImapClientInterface;
use App\Mailbox\Application\Imap\ImapConnectionConfig;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Exception\MailOauthException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Entitlements\Capability;
use App\Shared\Application\Entitlements\QuotaCheckerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * Conecta un buzón de correo a la organización activa.
 *
 * El orden importa: **primero se comprueba la conexión y solo después se
 * guarda**. Un buzón que no responde no debe quedar en la base de datos con
 * credenciales cifradas que no sirven para nada, y el usuario debe recibir el
 * error mientras tiene la contraseña delante (ARCHITECTURE.md §4.5).
 *
 * Antes van las comprobaciones **específicas** y después las **de política**:
 * un buzón que ya está conectado se rechaza con su propio mensaje (aunque el
 * plan esté lleno), y solo si el buzón es nuevo se mira el límite del plan
 * (Fase 17). Al revés, el usuario vería «tu plan no da para más buzones»
 * cuando lo que tiene es un duplicado, que es un error que él sí puede
 * corregir.
 *
 * Con un proveedor de catálogo (Gmail, Microsoft, Yahoo, iCloud) el servidor,
 * el puerto y el cifrado los pone el catálogo y no el formulario: el cliente no
 * decide a qué servidor se conecta la aplicación (D-93).
 */
final readonly class ConnectEmailAccount
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private ImapClientInterface $imapClient,
        private CredentialCipherInterface $cipher,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
        private QuotaCheckerInterface $quotas,
    ) {
    }

    /**
     * @throws ImapConnectionException si el servidor rechaza las credenciales
     * @throws MailOauthException      si el proveedor exige OAuth2 y no lo hay
     */
    public function __invoke(EmailAccountInput $input, ?Uuid $actorUserId = null): EmailAccount
    {
        $provider = $input->provider;
        $credentials = $input->credentials;

        if (null === $credentials) {
            throw new InvalidArgumentException('Faltan las credenciales del buzón.');
        }

        if (!$provider->isImplemented()) {
            throw new InvalidArgumentException(sprintf('El proveedor «%s» todavía no está disponible.', $provider->label()));
        }

        if ($provider->requiresOauth() && !$credentials->isOauth()) {
            throw new MailOauthException(sprintf('%s ya no acepta contraseñas: conecta la cuenta con el botón de Microsoft.', $provider->label()));
        }

        $organizationId = $this->tenantContext->requireOrganizationId();

        $existing = $this->accounts->findByAddress($input->emailAddress);

        if (null !== $existing && !$existing->isDeleted()) {
            throw new InvalidArgumentException('Ese buzón ya está conectado en esta organización.');
        }

        $this->quotas->assertWithinQuota(Capability::EMAIL_ACCOUNTS);

        $account = new EmailAccount(
            organizationId: $organizationId,
            emailAddress: $input->emailAddress,
            provider: $provider,
        );

        $preset = $provider->preset();

        $account->setDisplayName($input->displayName);
        $account->configureImap(
            host: $preset->host ?? $input->imapHost,
            encryption: $preset->encryption ?? $input->imapEncryption,
            port: $preset->port ?? $input->imapPort,
            username: $input->imapUsername,
        );
        $account->setImapFolder($input->imapFolder);

        $config = new ImapConnectionConfig(
            host: (string) $account->getImapHost(),
            port: (int) $account->getImapPort(),
            encryption: $account->getImapEncryption() ?? $input->imapEncryption,
            username: (string) $account->getImapUsername(),
            password: $credentials->secret(),
            authentication: $credentials->authentication(),
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

        $account->setCredentialsEncrypted($this->cipher->encrypt($credentials->toPlaintext()));
        $account->markActive();

        $this->accounts->save($account);

        $this->auditLogger->log(
            action: AuditAction::EMAIL_ACCOUNT_CONNECTED,
            targetType: 'email_account',
            targetId: $account->getId()->toRfc4122(),
            metadata: [
                'address' => $account->getEmailAddress(),
                'host' => $config->host,
                'provider' => $provider->value,
                'authentication' => $credentials->authentication()->value,
            ],
            actorUserId: $actorUserId,
        );

        return $account;
    }
}
