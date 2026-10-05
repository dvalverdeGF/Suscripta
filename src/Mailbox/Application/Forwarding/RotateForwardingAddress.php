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
 * Cambia la dirección de ingesta por reenvío (D-21).
 *
 * Es la respuesta a una fuga: si la dirección ha circulado, se rota y la
 * antigua deja de aceptar correo. Los remitentes autorizados se conservan.
 */
final readonly class RotateForwardingAddress
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private ForwardingAddressFactory $addresses,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(EmailAccount $account, ?Uuid $actorUserId = null): EmailAccount
    {
        if (!$account->isForwardingEnabled()) {
            throw new InvalidArgumentException('La cuenta no tiene activada la ingesta por reenvío.');
        }

        return $this->tenantContext->runAs(
            $account->getOrganizationId(),
            function () use ($account, $actorUserId): EmailAccount {
                $previous = $account->getForwardingAddress();
                $account->rotateForwardingAddress($this->addresses->generate());

                $this->accounts->save($account);

                $this->auditLogger->log(
                    action: AuditAction::EMAIL_FORWARDING_ROTATED,
                    targetType: 'email_account',
                    targetId: $account->getId()->toRfc4122(),
                    metadata: ['address' => $account->getEmailAddress(), 'previous' => $previous],
                    actorUserId: $actorUserId,
                );

                return $account;
            },
        );
    }
}
