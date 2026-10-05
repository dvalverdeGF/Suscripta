<?php

declare(strict_types=1);

namespace App\Identity\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Solicitud de restablecimiento de contraseña.
 *
 * Se guarda el **hash** del token, nunca el token en claro: si alguien lee la
 * base de datos no puede usarlo para entrar. El token viaja solo en el enlace
 * del correo. Es de un solo uso (`usedAt`) y caduca (SECURITY.md §3).
 */
#[ORM\Entity]
#[ORM\Table(name: 'password_reset_request')]
#[ORM\Index(name: 'idx_password_reset_token_hash', columns: ['token_hash'])]
class PasswordResetRequest
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 64)]
    private string $tokenHash;

    #[ORM\Column(name: 'requested_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $usedAt = null;

    #[ORM\Column(name: 'requested_ip', type: Types::STRING, length: 45, nullable: true)]
    private ?string $requestedIp = null;

    public function __construct(User $user, string $tokenHash, DateTimeImmutable $expiresAt, ?string $requestedIp = null)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->requestedAt = new DateTimeImmutable();
        $this->requestedIp = $requestedIp;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getRequestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        return null === $this->usedAt && $now < $this->expiresAt;
    }

    public function markUsed(DateTimeImmutable $at): void
    {
        $this->usedAt = $at;
    }
}
