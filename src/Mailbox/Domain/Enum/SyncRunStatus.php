<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Enum;

enum SyncRunStatus: string
{
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::RUNNING => 'En curso',
            self::COMPLETED => 'Completada',
            self::FAILED => 'Fallida',
        };
    }
}
