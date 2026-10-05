<?php

declare(strict_types=1);

namespace App\Notifications\Application\Channel;

use App\Notifications\Domain\Enum\NotificationStatus;

/**
 * Qué ha pasado al intentar entregar un aviso por un canal.
 *
 * Se distinguen tres desenlaces y no dos porque «no se envió» y «no se pudo
 * enviar» son cosas distintas: lo primero es una decisión (el usuario lo tiene
 * desactivado) y no debe reintentarse ni contarse como error; lo segundo sí.
 */
final readonly class DeliveryResult
{
    private function __construct(
        public NotificationStatus $status,
        public ?string $detail = null,
    ) {
    }

    public static function sent(): self
    {
        return new self(NotificationStatus::SENT);
    }

    public static function skipped(string $reason): self
    {
        return new self(NotificationStatus::SKIPPED, $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(NotificationStatus::FAILED, $reason);
    }

    public function isFailure(): bool
    {
        return NotificationStatus::FAILED === $this->status;
    }
}
