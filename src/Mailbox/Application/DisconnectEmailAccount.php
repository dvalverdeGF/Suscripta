<?php

declare(strict_types=1);

namespace App\Mailbox\Application;

use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailSyncCursorRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailSyncRunRepositoryInterface;
use App\Mailbox\Domain\Repository\MessageProcessingEventRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use Symfony\Component\Uid\Uuid;

/**
 * Desconecta un buzón sin borrar nada de lo ya descubierto.
 *
 * Es la operación que un usuario espera cuando quiere «dejar de leer mi
 * correo»: se destruyen las credenciales, se borran los mensajes indexados,
 * los cursores, las ejecuciones de sincronización, los eventos de
 * procesamiento y lo que el buzón había aprendido, y se detiene la ingesta.
 * Los servicios y el histórico de precios siguen ahí porque son suyos, no
 * del correo (SECURITY.md §6).
 *
 * El reenvío se desactiva también: si el usuario cree haber dejado de compartir
 * su correo, la dirección de reenvío no puede seguir aceptando mensajes.
 */
final readonly class DisconnectEmailAccount
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private EmailMessageRepositoryInterface $messages,
        private EmailSyncCursorRepositoryInterface $cursors,
        private EmailSyncRunRepositoryInterface $runs,
        private MessageProcessingEventRepositoryInterface $events,
        private MailboxKnowledgeCleanerInterface $knowledgeCleaner,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(EmailAccount $account, ?Uuid $actorUserId = null): void
    {
        $accountId = $account->getId();

        $removedEvents = $this->events->removeForAccount($accountId);
        $removedMessages = $this->messages->removeForAccount($accountId);
        $removedCursors = $this->cursors->removeForAccount($accountId);
        $removedRuns = $this->runs->removeForAccount($accountId);
        $removedKnowledge = $this->knowledgeCleaner->removeForAccount($accountId);

        $account->setCredentialsEncrypted(null);
        $account->disableForwarding();
        $account->disable();

        $this->accounts->save($account);

        $this->auditLogger->log(
            action: AuditAction::EMAIL_ACCOUNT_DISCONNECTED,
            targetType: 'email_account',
            targetId: $accountId->toRfc4122(),
            metadata: [
                'address' => $account->getEmailAddress(),
                'messagesRemoved' => $removedMessages,
                'eventsRemoved' => $removedEvents,
                'cursorsRemoved' => $removedCursors,
                'runsRemoved' => $removedRuns,
                'knowledgeRemoved' => $removedKnowledge,
            ],
            actorUserId: $actorUserId,
        );
    }
}
