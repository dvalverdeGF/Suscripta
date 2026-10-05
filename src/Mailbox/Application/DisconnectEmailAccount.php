<?php

declare(strict_types=1);

namespace App\Mailbox\Application;

use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use Symfony\Component\Uid\Uuid;

/**
 * Desconecta un buzón sin borrar nada de lo ya descubierto.
 *
 * Es la operación que un usuario espera cuando quiere «dejar de leer mi
 * correo»: se destruyen las credenciales y se detiene la sincronización, pero
 * los servicios y el histórico de precios siguen ahí porque son suyos
 * (SECURITY.md §6).
 */
final readonly class DisconnectEmailAccount
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(EmailAccount $account, ?Uuid $actorUserId = null): void
    {
        $account->setCredentialsEncrypted(null);
        $account->disable();

        $this->accounts->save($account);

        $this->auditLogger->log(
            action: AuditAction::EMAIL_ACCOUNT_DISCONNECTED,
            targetType: 'email_account',
            targetId: $account->getId()->toRfc4122(),
            metadata: ['address' => $account->getEmailAddress()],
            actorUserId: $actorUserId,
        );
    }
}
