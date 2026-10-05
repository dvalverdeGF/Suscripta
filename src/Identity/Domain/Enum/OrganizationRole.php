<?php

declare(strict_types=1);

namespace App\Identity\Domain\Enum;

use function in_array;

enum OrganizationRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case MEMBER = 'member';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Propietario',
            self::ADMIN => 'Administrador',
            self::MEMBER => 'Miembro',
        };
    }

    public function canManageMembers(): bool
    {
        return in_array($this, [self::OWNER, self::ADMIN], true);
    }

    public function canManageBilling(): bool
    {
        return self::OWNER === $this;
    }
}
