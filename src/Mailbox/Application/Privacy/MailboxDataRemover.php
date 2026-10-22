<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Privacy;

use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailSyncCursorRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailSyncRunRepositoryInterface;
use App\Mailbox\Domain\Repository\MessageProcessingEventRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataRemoverInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Borra los buzones y todo lo que se indexó desde ellos.
 *
 * El orden importa: primero los hijos (mensajes, cursores, ejecuciones y
 * eventos) y después las cuentas. Al revés dejaría filas apuntando a una
 * cuenta que ya no existe.
 *
 * El **conocimiento aprendido** del buzón no se borra aquí: vive en el módulo
 * de procesamiento, que es quien sabe qué es y cómo se poda. Ese módulo
 * publica su propio removedor y lo ejecuta en su turno.
 */
final readonly class MailboxDataRemover implements OrganizationDataRemoverInterface
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private EmailMessageRepositoryInterface $messages,
        private EmailSyncCursorRepositoryInterface $cursors,
        private EmailSyncRunRepositoryInterface $runs,
        private MessageProcessingEventRepositoryInterface $events,
    ) {
    }

    public function module(): string
    {
        return 'mailbox';
    }

    /**
     * El buzón se va después de todo lo que se aprendió de él, que es el
     * módulo de procesamiento.
     */
    public function priority(): int
    {
        return 70;
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        $accounts = $this->accounts->findAllForOrganization($organizationId);

        $removed = 0;

        foreach ($accounts as $account) {
            $accountId = $account->getId();

            $removed += $this->events->removeForAccount($accountId);
            $removed += $this->cursors->removeForAccount($accountId);
            $removed += $this->runs->removeForAccount($accountId);
            $removed += $this->messages->removeForAccount($accountId);
        }

        $removed += $this->accounts->removeForOrganization($organizationId);

        return $removed;
    }
}
