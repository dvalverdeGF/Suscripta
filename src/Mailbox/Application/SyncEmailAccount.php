<?php

declare(strict_types=1);

namespace App\Mailbox\Application;

use App\Mailbox\Application\Imap\ImapClientInterface;
use App\Mailbox\Application\Imap\ImapConnectionConfig;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Entity\EmailSyncCursor;
use App\Mailbox\Domain\Entity\EmailSyncRun;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Enum\SyncRunStatus;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Exception\ImapFetchException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailSyncCursorRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailSyncRunRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function count;
use function max;
use function min;
use function sprintf;

use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Sincroniza un buzón: niveles 0 y 1 del pipeline (ARCHITECTURE.md §13.2 y
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
 * **Incrementalidad.** El cursor (`EmailSyncCursor`) recuerda hasta qué UID se
 * leyó, de modo que una pasada normal pide solo lo que ha llegado. El cursor se
 * reinicia si el servidor cambia el `UIDVALIDITY` de la carpeta, porque a partir
 * de ese momento los UID antiguos ya no significan lo mismo.
 *
 * **Backfill progresivo.** La primera lectura está acotada por ventana y por
 * lote. Si el buzón tiene más correo del que cabe en una pasada, el cursor
 * recuerda por dónde iba la lectura hacia atrás y cada pasada posterior avanza
 * un tramo más hacia el pasado, sin bloquear la llegada de correo nuevo.
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
        private EmailSyncCursorRepositoryInterface $cursors,
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
            $config = $this->connectionConfig($account);
            $cursor = $this->cursorFor($account);

            $this->observeUidValidity($account, $cursor, $config, $actorUserId);

            $this->readNewMessages($account, $cursor, $config, $run, $months, $limit);
            $this->readBackfill($account, $cursor, $config, $run, $limit);

            $this->messages->flush();

            $cursor->recordSync($this->clock->now());
            $this->cursors->save($cursor);

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
                'backfilled' => $run->getMessagesBackfilled(),
            ],
            actorUserId: $actorUserId,
        );

        return $run;
    }

    /**
     * Comprueba el `UIDVALIDITY` y reinicia el cursor si el servidor ha
     * renumerado la carpeta.
     *
     * Es la salvaguarda que impide el peor fallo posible de una sincronización
     * incremental: saltarse mensajes en silencio porque el número que
     * guardábamos ya no apunta al mismo sitio.
     */
    private function observeUidValidity(
        EmailAccount $account,
        EmailSyncCursor $cursor,
        ImapConnectionConfig $config,
        ?Uuid $actorUserId,
    ): void {
        // En la primera lectura el cursor todavía no sabe nada, así que el
        // cambio de UIDVALIDITY es la simple toma de contacto, no un reinicio.
        $wasInitial = $cursor->isInitial();
        $uidValidity = $this->imapClient->getUidValidity($config, $account->getImapFolder());

        if (!$cursor->observeUidValidity($uidValidity) || $wasInitial) {
            return;
        }

        $this->auditLogger->log(
            action: AuditAction::EMAIL_SYNC_CURSOR_RESET,
            targetType: 'email_account',
            targetId: $account->getId()->toRfc4122(),
            metadata: [
                'address' => $account->getEmailAddress(),
                'folder' => $account->getImapFolder(),
                'uid_validity' => $uidValidity,
            ],
            actorUserId: $actorUserId,
        );
    }

    /**
     * Lee lo que ha llegado desde la última vez.
     *
     * La primera pasada usa una ventana por fecha, porque todavía no hay UID
     * del que partir. A partir de ahí, la lectura es por UID: más barata y sin
     * depender de la fecha que el servidor haya puesto al mensaje.
     */
    private function readNewMessages(
        EmailAccount $account,
        EmailSyncCursor $cursor,
        ImapConnectionConfig $config,
        EmailSyncRun $run,
        int $months,
        int $limit,
    ): void {
        $folder = $account->getImapFolder();

        if ($cursor->isInitial()) {
            $headers = $this->imapClient->fetchHeaders(
                $config,
                $folder,
                $this->clock->now()->modify(sprintf('-%d months', max(1, $months))),
                $limit,
            );

            $this->store($account, $headers, $run);

            if ([] === $headers) {
                $cursor->completeBackfill();

                return;
            }

            $uids = $this->uids($headers);

            if ([] === $uids) {
                $cursor->completeBackfill();

                return;
            }

            $cursor->advanceTo(max($uids));

            // Si la ventana se ha llenado, es que hay más correo del que cabe
            // en una pasada: se recuerda por dónde seguir hacia atrás.
            if (count($headers) >= $limit) {
                $cursor->setBackfillCursor(min($uids));
            } else {
                $cursor->completeBackfill();
            }

            return;
        }

        $headers = $this->imapClient->fetchHeadersAfter($config, $folder, $cursor->getLastSeenUid(), $limit);

        if ([] === $headers) {
            return;
        }

        $this->store($account, $headers, $run);

        $uids = $this->uids($headers);

        if ([] !== $uids) {
            $cursor->advanceTo(max($uids));
        }
    }

    /**
     * Recupera un tramo de histórico, si queda.
     *
     * Se hace **después** de leer el correo nuevo, nunca antes: lo que acaba de
     * llegar es lo que el usuario espera ver, y el histórico puede esperar a la
     * siguiente pasada.
     */
    private function readBackfill(
        EmailAccount $account,
        EmailSyncCursor $cursor,
        ImapConnectionConfig $config,
        EmailSyncRun $run,
        int $limit,
    ): void {
        if (!$cursor->hasBackfillPending()) {
            return;
        }

        $headers = $this->imapClient->fetchHeadersBefore($config, $account->getImapFolder(), $cursor->backfillFrom(), $limit);

        if ([] === $headers) {
            $cursor->completeBackfill();

            return;
        }

        $this->store($account, $headers, $run, backfill: true);

        $uids = $this->uids($headers);

        if ([] === $uids) {
            $cursor->completeBackfill();

            return;
        }

        $lowest = min($uids);

        if (count($headers) >= $limit && $lowest > 1) {
            $cursor->setBackfillCursor($lowest);
        } else {
            $cursor->completeBackfill();
        }
    }

    /**
     * @param list<ImapMessageHeader> $headers
     */
    private function store(EmailAccount $account, array $headers, EmailSyncRun $run, bool $backfill = false): void
    {
        $run->countSeen(count($headers));

        foreach ($headers as $header) {
            if ($this->alreadyKnown($account, $header)) {
                $run->countSkipped();

                continue;
            }

            $this->messages->save($this->createMessage($account, $header), false);
            $run->countProcessed();

            if ($backfill) {
                $run->countBackfilled();
            }
        }
    }

    /**
     * Los UID de un lote. Un mensaje reenviado no tiene UID, pero por esta vía
     * todos vienen del buzón, así que el filtro es una salvaguarda de tipos.
     *
     * @param list<ImapMessageHeader> $headers
     *
     * @return list<int>
     */
    private function uids(array $headers): array
    {
        $uids = [];

        foreach ($headers as $header) {
            if (null !== $header->uid) {
                $uids[] = $header->uid;
            }
        }

        return $uids;
    }

    private function cursorFor(EmailAccount $account): EmailSyncCursor
    {
        $cursor = $this->cursors->findForAccountFolder($account->getId(), $account->getImapFolder());

        if (null !== $cursor) {
            return $cursor;
        }

        $cursor = new EmailSyncCursor(
            organizationId: $account->getOrganizationId(),
            emailAccountId: $account->getId(),
            folder: $account->getImapFolder(),
            createdAt: $this->clock->now(),
        );

        $this->cursors->save($cursor, false);

        return $cursor;
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
        if (null !== $header->uid
            && null !== $this->messages->findByAccountFolderUid($account->getId(), $account->getImapFolder(), $header->uid)) {
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
