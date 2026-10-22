<?php

declare(strict_types=1);

namespace App\Api\Application\Dto;

use App\Api\Domain\Enum\ApiScope;

/**
 * Datos para emitir una clave de API.
 *
 * `expiresInDays` en lugar de una fecha: el panel ofrece «30 días», «90 días»,
 * «un año» o «nunca», y una fecha absoluta depende de la zona horaria del
 * navegador y del servidor. El caso de uso la convierte a fecha absoluta.
 */
final readonly class ApiKeyInput
{
    /**
     * @param list<ApiScope> $scopes
     */
    public function __construct(
        public string $name,
        public array $scopes = [ApiScope::Read],
        public ?int $expiresInDays = null,
    ) {
    }
}
