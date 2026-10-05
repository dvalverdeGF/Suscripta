<?php

declare(strict_types=1);

namespace App\Notifications\Application\Dto;

/**
 * Resumen de una pasada de entrega de avisos.
 */
final readonly class NotificationDispatchResult
{
    public function __construct(
        public int $sent = 0,
        public int $skipped = 0,
        public int $failed = 0,
        public int $alreadyDelivered = 0,
    ) {
    }

    public function total(): int
    {
        return $this->sent + $this->skipped + $this->failed + $this->alreadyDelivered;
    }
}
