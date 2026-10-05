<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Enum;

/**
 * Forma en la que un proveedor se manifiesta en un correo.
 */
enum ProviderIdentityType: string
{
    case DOMAIN = 'domain';
    case SENDER = 'sender';
    case SUBJECT_PATTERN = 'subject_pattern';
    case ATTACHMENT_PATTERN = 'attachment_pattern';

    public function label(): string
    {
        return match ($this) {
            self::DOMAIN => 'Dominio',
            self::SENDER => 'Dirección de envío',
            self::SUBJECT_PATTERN => 'Patrón de asunto',
            self::ATTACHMENT_PATTERN => 'Patrón de adjunto',
        };
    }
}
