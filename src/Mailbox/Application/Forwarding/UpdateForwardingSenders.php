<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Forwarding;

use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Cambia la lista de remitentes autorizados a reenviar (SECURITY.md §2.4).
 *
 * Sin esta lista, la dirección de ingesta sería un buzón abierto: cualquiera
 * que la descubriera podría meter facturas falsas en el inventario.
 */
final readonly class UpdateForwardingSenders
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    /**
     * @param list<string> $senders
     */
    public function __invoke(EmailAccount $account, array $senders, ?Uuid $actorUserId = null): void
    {
        if (!$account->isForwardingEnabled()) {
            throw new InvalidArgumentException('La cuenta no tiene activada la ingesta por reenvío.');
        }

        $this->tenantContext->runAs(
            $account->getOrganizationId(),
            function () use ($account, $senders, $actorUserId): void {
                $account->setForwardingSenders($senders);
                $this->accounts->save($account);

                $this->auditLogger->log(
                    action: AuditAction::EMAIL_FORWARDING_SENDERS_UPDATED,
                    targetType: 'email_account',
                    targetId: $account->getId()->toRfc4122(),
                    metadata: [
                        'address' => $account->getEmailAddress(),
                        'senders' => implode(', ', $account->getForwardingSenders()),
                    ],
                    actorUserId: $actorUserId,
                );
            },
        );
    }
}
