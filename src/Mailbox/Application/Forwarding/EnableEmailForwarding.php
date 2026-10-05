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
 * Activa la ingesta por reenvío de una cuenta (D-21).
 *
 * Es la vía para quien no quiere dar acceso a su buzón: se le da una dirección
 * dedicada y reenvía ahí sus facturas. No requiere credenciales ni OAuth, y por
 * tanto tampoco la verificación de Google.
 */
final readonly class EnableEmailForwarding
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private ForwardingAddressFactory $addresses,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    /**
     * @param list<string> $senders remitentes autorizados a reenviar
     */
    public function __invoke(EmailAccount $account, array $senders, ?Uuid $actorUserId = null): EmailAccount
    {
        return $this->tenantContext->runAs(
            $account->getOrganizationId(),
            function () use ($account, $senders, $actorUserId): EmailAccount {
                $alreadyEnabled = $account->isForwardingEnabled();

                if ($alreadyEnabled) {
                    $account->setForwardingSenders($senders);
                } else {
                    $account->enableForwarding($this->addresses->generate(), $senders);
                }

                $this->accounts->save($account);

                $this->auditLogger->log(
                    action: AuditAction::EMAIL_FORWARDING_ENABLED,
                    targetType: 'email_account',
                    targetId: $account->getId()->toRfc4122(),
                    metadata: [
                        'address' => $account->getEmailAddress(),
                        'senders' => implode(', ', $account->getForwardingSenders()),
                        'rotated' => $alreadyEnabled,
                    ],
                    actorUserId: $actorUserId,
                );

                return $account;
            },
        );
    }
}
