<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Entity\PasswordResetRequest;
use App\Identity\Domain\Repository\PasswordResetRequestRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Consume un enlace de restablecimiento y cambia la contraseña.
 *
 * El token es de un solo uso: se marca como usado en la misma transacción en la
 * que se cambia la contraseña, de modo que un enlace interceptado no sirve dos
 * veces.
 */
final readonly class ResetPassword
{
    public function __construct(
        private PasswordResetRequestRepositoryInterface $requests,
        private UserRepositoryInterface $users,
        private UserPasswordHasherInterface $passwordHasher,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(string $token, string $plainPassword): void
    {
        $request = $this->requests->findByTokenHash(RequestPasswordReset::hash($token));

        if (!$request instanceof PasswordResetRequest) {
            throw new InvalidArgumentException('El enlace de restablecimiento no es válido.');
        }

        $now = new DateTimeImmutable();

        if (null !== $request->getUsedAt()) {
            throw new InvalidArgumentException('El enlace de restablecimiento ya se ha usado.');
        }

        if ($now >= $request->getExpiresAt()) {
            throw new InvalidArgumentException('El enlace de restablecimiento ha caducado. Pide uno nuevo.');
        }

        $user = $request->getUser();
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->users->save($user, false);

        $request->markUsed($now);
        $this->requests->save($request);

        $this->auditLogger->log(
            action: AuditAction::USER_PASSWORD_CHANGED,
            targetType: 'user',
            targetId: $user->getId()->toRfc4122(),
            metadata: ['via' => 'reset'],
        );
    }
}
