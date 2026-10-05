<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Enum;

/**
 * De dónde sale el conocimiento de que un proveedor se reconoce así.
 */
enum ProviderIdentitySource: string
{
    /** Sembrado con el catálogo inicial. */
    case SEED = 'seed';

    /** Aprendido del buzón tras una confirmación del usuario. */
    case LEARNED = 'learned';

    /** Añadido a mano por el usuario. */
    case USER = 'user';

    public function label(): string
    {
        return match ($this) {
            self::SEED => 'Catálogo',
            self::LEARNED => 'Aprendido',
            self::USER => 'Añadido por ti',
        };
    }
}
