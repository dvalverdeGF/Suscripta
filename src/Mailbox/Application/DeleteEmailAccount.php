<?php

declare(strict_types=1);

namespace App\Mailbox\Application;

use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Domain\Enum\AuditAction;
use Symfony\Component\Uid\Uuid;

/**
 * Elimina un buzón y todo lo que se derivó de él.
 *
 * Es la materialización del derecho de supresión (SECURITY.md §6): se borran
 * los mensajes indexados y las credenciales, y se deja constancia en el libro
 * de auditoría. Los servicios que el usuario confirmó **no** se borran: son
 * datos suyos, no del correo.
 */
final readonly class DeleteEmailAccount
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private EmailMessageRepositoryInterface $messages,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
    ) {
    }

    public function __invoke(EmailAccount $account, ?Uuid $actorUserId = null): void
    {
        $address = $account->getEmailAddress();
        $accountId = $account->getId();

        $this->messages->removeForAccount($accountId);

        $account->markDeleted($this->clock->now());
        $this->accounts->save($account);

        $this->auditLogger->log(
            action: AuditAction::EMAIL_ACCOUNT_DELETED,
            targetType: 'email_account',
            targetId: $accountId->toRfc4122(),
            metadata: ['address' => $address],
            actorUserId: $actorUserId,
        );
    }
}
