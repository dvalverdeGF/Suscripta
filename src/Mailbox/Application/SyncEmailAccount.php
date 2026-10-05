<?php

declare(strict_types=1);

namespace App\Mailbox\Application;

use App\Mailbox\Application\Imap\ImapClientInterface;
use App\Mailbox\Application\Imap\ImapConnectionConfig;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Entity\EmailSyncRun;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Enum\SyncRunStatus;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Exception\ImapFetchException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailSyncRunRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function count;
use function sprintf;

use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Sincroniza un buzón: nivel 0 y nivel 1 del pipeline (ARCHITECTURE.md §13.2 y
 * §13.3).
 *
 * **Solo descarga metadatos.** El cuerpo de los mensajes no se toca aquí: eso
 * ocurre más adelante y solo para los mensajes que superan el filtro
 * determinista. Es la diferencia entre analizar un buzón de veinte mil correos
 * y analizar los cuarenta que importan.
 *
 * **Idempotencia (D-37).** Un mensaje ya visto no se vuelve a procesar: se
 * detecta por `(cuenta, carpeta, uid)` y, si el mensaje se ha movido de carpeta,
 * por `(cuenta, messageId)`. Volver a sincronizar el mismo buzón no cuesta nada
 * y no puede duplicar nada.
 *
 * **Solo lectura (SECURITY.md §2).** No existe ninguna operación de escritura
 * sobre el buzón en todo el flujo.
 */
final readonly class SyncEmailAccount
{
    /**
     * Ventana por defecto. Seis meses cubren de sobra las suscripciones
     * mensuales, trimestrales y semestrales, que son la inmensa mayoría.
     */
    public const int DEFAULT_MONTHS = 6;

    /**
     * Tope de mensajes por pasada. Un buzón con cien mil correos no debe
     * convertir la primera sincronización en un incidente.
     */
    public const int DEFAULT_LIMIT = 200;

    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private EmailMessageRepositoryInterface $messages,
        private EmailSyncRunRepositoryInterface $runs,
        private ImapClientInterface $imapClient,
        private CredentialCipherInterface $cipher,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
    ) {
    }

    /**
     * @throws ImapConnectionException
     * @throws ImapFetchException
     */
    public function __invoke(
        EmailAccount $account,
        ?Uuid $actorUserId = null,
        int $months = self::DEFAULT_MONTHS,
        int $limit = self::DEFAULT_LIMIT,
    ): EmailSyncRun {
        if (!$account->isConfigured()) {
            throw new InvalidArgumentException('La cuenta no tiene configurado el servidor IMAP o le faltan las credenciales.');
        }

        return $this->tenantContext->runAs(
            $account->getOrganizationId(),
            fn (): EmailSyncRun => $this->sync($account, $actorUserId, $months, $limit),
        );
    }

    private function sync(EmailAccount $account, ?Uuid $actorUserId, int $months, int $limit): EmailSyncRun
    {
        $run = new EmailSyncRun($account->getOrganizationId(), $account->getId(), $this->clock->now());
        $this->runs->save($run);

        $this->auditLogger->log(
            action: AuditAction::EMAIL_SYNC_STARTED,
            targetType: 'email_account',
            targetId: $account->getId()->toRfc4122(),
            metadata: ['address' => $account->getEmailAddress(), 'folder' => $account->getImapFolder()],
            actorUserId: $actorUserId,
        );

        try {
            $headers = $this->imapClient->fetchHeaders(
                $this->connectionConfig($account),
                $account->getImapFolder(),
                $this->clock->now()->modify(sprintf('-%d months', $months)),
                $limit,
            );

            $run->countSeen(count($headers));

            foreach ($headers as $header) {
                if ($this->alreadyKnown($account, $header)) {
                    $run->countSkipped();

                    continue;
                }

                $this->messages->save($this->createMessage($account, $header), false);
                $run->countProcessed();
            }

            $this->messages->flush();

            $run->complete($this->clock->now());
            $account->recordSync(SyncRunStatus::COMPLETED, $this->clock->now());
            $account->markActive();
        } catch (Throwable $e) {
            $run->fail($this->clock->now(), $e->getMessage());
            $account->recordSync(SyncRunStatus::FAILED, $this->clock->now(), $e->getMessage());
            $account->markError($e->getMessage());

            $this->runs->save($run);
            $this->accounts->save($account);

            $this->auditLogger->log(
                action: AuditAction::EMAIL_SYNC_FINISHED,
                targetType: 'email_account',
                targetId: $account->getId()->toRfc4122(),
                metadata: ['address' => $account->getEmailAddress(), 'status' => 'failed'],
                actorUserId: $actorUserId,
            );

            throw $e;
        }

        $this->runs->save($run);
        $this->accounts->save($account);

        $this->auditLogger->log(
            action: AuditAction::EMAIL_SYNC_FINISHED,
            targetType: 'email_account',
            targetId: $account->getId()->toRfc4122(),
            metadata: [
                'address' => $account->getEmailAddress(),
                'status' => 'completed',
                'seen' => $run->getMessagesSeen(),
                'processed' => $run->getMessagesProcessed(),
                'skipped' => $run->getMessagesSkipped(),
            ],
            actorUserId: $actorUserId,
        );

        return $run;
    }

    private function connectionConfig(EmailAccount $account): ImapConnectionConfig
    {
        return new ImapConnectionConfig(
            host: (string) $account->getImapHost(),
            port: (int) $account->getImapPort(),
            encryption: $account->getImapEncryption() ?? ImapEncryption::SSL,
            username: (string) $account->getImapUsername(),
            password: $this->cipher->decrypt((string) $account->getCredentialsEncrypted()),
        );
    }

    /**
     * Deduplicación en dos pasos (§13.2): la clave primaria es
     * `(cuenta, carpeta, uid)`; si el mensaje se movió de carpeta, el
     * `messageId` lo delata.
     */
    private function alreadyKnown(EmailAccount $account, ImapMessageHeader $header): bool
    {
        if (null !== $this->messages->findByAccountFolderUid($account->getId(), $account->getImapFolder(), $header->uid)) {
            return true;
        }

        if (null === $header->messageId || '' === $header->messageId) {
            return false;
        }

        return null !== $this->messages->findByAccountAndMessageId($account->getId(), $header->messageId);
    }

    private function createMessage(EmailAccount $account, ImapMessageHeader $header): EmailMessage
    {
        $message = new EmailMessage(
            organizationId: $account->getOrganizationId(),
            emailAccountId: $account->getId(),
            folder: $account->getImapFolder(),
            uid: $header->uid,
        );

        $message->applyMetadata(
            messageId: $header->messageId,
            fromAddress: $header->fromAddress,
            fromName: $header->fromName,
            replyTo: $header->replyTo,
            senderDomain: $header->senderDomain(),
            toAddresses: $header->toAddresses,
            subject: $header->subject,
            receivedAt: $header->receivedAt,
            sizeBytes: $header->sizeBytes,
            contentType: $header->contentType,
            attachmentNames: $header->attachmentNames,
            attachmentTypes: $header->attachmentTypes,
        );

        return $message;
    }
}
