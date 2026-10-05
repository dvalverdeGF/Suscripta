<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Forwarding;

use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use Symfony\Component\Uid\Uuid;

/**
 * Desactiva la ingesta por reenvío (D-21).
 *
 * La dirección se conserva para poder reactivarla, pero deja de aceptar correo:
 * `findByForwardingAddress()` exige `forwardingEnabled = true`.
 */
final readonly class DisableEmailForwarding
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(EmailAccount $account, ?Uuid $actorUserId = null): void
    {
        $this->tenantContext->runAs(
            $account->getOrganizationId(),
            function () use ($account, $actorUserId): void {
                $account->disableForwarding();
                $this->accounts->save($account);

                $this->auditLogger->log(
                    action: AuditAction::EMAIL_FORWARDING_DISABLED,
                    targetType: 'email_account',
                    targetId: $account->getId()->toRfc4122(),
                    metadata: ['address' => $account->getEmailAddress()],
                    actorUserId: $actorUserId,
                );
            },
        );
    }
}
