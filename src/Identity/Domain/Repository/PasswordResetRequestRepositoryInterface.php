<?php

declare(strict_types=1);

namespace App\Identity\Domain\Repository;

use App\Identity\Domain\Entity\PasswordResetRequest;
use App\Identity\Domain\Entity\User;
use DateTimeImmutable;

interface PasswordResetRequestRepositoryInterface
{
    public function findByTokenHash(string $tokenHash): ?PasswordResetRequest;

    /**
     * Invalida las solicitudes anteriores del usuario.
     *
     * Solo puede haber un enlace vivo a la vez: si se pide otro, el anterior
     * deja de funcionar. Así un enlace filtrado no sobrevive a un segundo
     * restablecimiento.
     */
    public function invalidatePendingFor(User $user, DateTimeImmutable $at): void;

    public function save(PasswordResetRequest $request, bool $flush = true): void;
}
