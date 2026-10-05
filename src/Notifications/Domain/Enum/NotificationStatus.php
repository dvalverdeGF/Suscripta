<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Enum;

enum NotificationStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::SENT => 'Enviada',
            self::FAILED => 'Fallida',
            self::SKIPPED => 'Omitida',
        };
    }
}
