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
use App\Shared\Application\Clock;
use App\Shared\Domain\Enum\AuditAction;
use Symfony\Component\Uid\Uuid;

/**
 * Elimina un buzón y todo lo que se derivó de él.
 *
 * Es la materialización del derecho de supresión (SECURITY.md §6): se borran
 * los mensajes indexados, los cursores, las ejecuciones de sincronización,
 * los eventos de procesamiento y lo que el buzón había aprendido. La
 * cuenta queda marcada como borrada **sin credenciales**: el texto cifrado
 * de la contraseña desaparece con ella.
 *
 * Los servicios que el usuario confirmó **no** se borran: son datos suyos, no
 * del correo.
 */
final readonly class DeleteEmailAccount
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private EmailMessageRepositoryInterface $messages,
        private EmailSyncCursorRepositoryInterface $cursors,
        private EmailSyncRunRepositoryInterface $runs,
        private MessageProcessingEventRepositoryInterface $events,
        private MailboxKnowledgeCleanerInterface $knowledgeCleaner,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
    ) {
    }

    public function __invoke(EmailAccount $account, ?Uuid $actorUserId = null): void
    {
        $address = $account->getEmailAddress();
        $accountId = $account->getId();

        // El orden va de los hijos a los padres: los eventos apuntan a los
        // mensajes, y los mensajes cuelgan de la cuenta.
        $removedEvents = $this->events->removeForAccount($accountId);
        $removedMessages = $this->messages->removeForAccount($accountId);
        $removedCursors = $this->cursors->removeForAccount($accountId);
        $removedRuns = $this->runs->removeForAccount($accountId);
        $removedKnowledge = $this->knowledgeCleaner->removeForAccount($accountId);

        // `markDeleted()` borra además las credenciales cifradas.
        $account->markDeleted($this->clock->now());
        $this->accounts->save($account);

        $this->auditLogger->log(
            action: AuditAction::EMAIL_ACCOUNT_DELETED,
            targetType: 'email_account',
            targetId: $accountId->toRfc4122(),
            metadata: [
                'address' => $address,
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
