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

    /**
     * Un patrón es una expresión regular, no un dominio.
     *
     * La distinción importa porque el valor de una identidad se normaliza a
     * minúsculas para poder compararlo con el remitente, y aplicar eso a una
     * expresión regular la rompe en silencio: `/^Tu factura de OVH/` se
     * convertiría en `/^tu factura de ovh/` y dejaría de coincidir con el
     * asunto real.
     */
    public function isPattern(): bool
    {
        return self::SUBJECT_PATTERN === $this || self::ATTACHMENT_PATTERN === $this;
    }
}
