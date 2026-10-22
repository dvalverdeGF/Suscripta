<?php

declare(strict_types=1);

namespace App\Api\UI\Form;

use App\Api\Domain\Enum\ApiScope;

use function array_values;
use function is_string;

/**
 * Datos del formulario de alta de una clave de API.
 *
 * ## Los ámbitos llegan como casillas
 *
 * El formulario tiene una casilla por ámbito, no un campo de texto: los
 * ámbitos son un conjunto cerrado y conocido, y una casilla deja claro que
 * `write` es más que `read` (además de incluirlo).
 *
 * La conversión a enumeración la hace el propio DTO, no el controlador: así
 * el caso de uso recibe siempre `ApiScope` y no texto.
 */
final class ApiKeyFormData
{
    public ?string $name = null;

    /**
     * @var list<string>
     */
    public array $scopes = [ApiScope::Read->value];

    /**
     * Validez en días. `null` significa «no caduca».
     */
    public ?int $expiresInDays = null;

    /**
     * @return list<ApiScope>
     */
    public function toScopes(): array
    {
        $scopes = [];

        foreach ($this->scopes as $scope) {
            if (!is_string($scope)) {
                continue;
            }

            $enumeration = ApiScope::tryFrom($scope);

            if (null === $enumeration) {
                continue;
            }

            $scopes[] = $enumeration;
        }

        return $scopes;
    }
}
